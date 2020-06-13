<?php

namespace app\modules\system\behaviors\models\ar;

use app\modules\containers\models\ar\ContainerModelValue;
use yii\base\Exception;
use yii\base\Behavior;
use app\modules\system\db\ActiveRecord;
use yii\db\Query;
use yii\validators\Validator;
use Yii;

/**
 * Class ContainerRelationBehavior это поведение сохраняет связи моделей с контейнерами после записи, апдейта
 * перед этими действиями добавляет валидаторы
 * @package app\modules\system\behaviors\models\db
 */
class ContainerRelationARBehavior extends Behavior
{
    /**
     * @var \app\modules\system\db\ActiveRecord
     */
    public $owner;

    /**
     * @var array
     */
    public $_containerIds = [];

    /**
     * @var array список родительских контейнеров по отношению к тем, к которым привязван пользователь
     */
    private $_parentContainerIds;

    /**
     * @var array список всех дочерних контейнеров по отношению к тем, к которым привязан пользователь
     */
    private $_childrenContainerIds;

    /**
     * @var bool нужно ли добавлять контейнерный валидатор
     */
    public $attachValidator = true;

    private $_isChanged = false;

    /**
     * @inheritdoc
     */
    public function events()
    {
        return [
            ActiveRecord::EVENT_AFTER_FIND => 'handleAfterFind',
            ActiveRecord::EVENT_AFTER_DELETE => 'handleAfterDelete',
            ActiveRecord::EVENT_AFTER_INSERT => 'handleAfterInsert',
            ActiveRecord::EVENT_AFTER_UPDATE => 'handleAfterUpdate',
            ActiveRecord::EVENT_BEFORE_INSERT => 'handleBeforeValidate',
            ActiveRecord::EVENT_BEFORE_UPDATE => 'handleBeforeValidate',
            ActiveRecord::EVENT_BEFORE_VALIDATE => 'handleBeforeValidate',
        ];
    }

    /**
     * Обработчик события после того, как модель была найдена.
     */
    public function handleAfterFind()
    {
        $this->loadContainerIds();
    }

    /**
     * Удаляем связи с контейнерами.
     * @throws \Exception
     */
    public function handleAfterDelete()
    {
        $containerModelValues = $this->owner->containerModelValue;
        /** @var ContainerModelValue $value */
        $this->_isChanged = true;
        foreach($containerModelValues as $value) {
            // Только если пользователь имеет доступ к контейнеру
            if (in_array($value->container_id, Yii::$app->user->childrenContainerIds)) {
                $value->delete();
            }
        }
    }

    /**
     * Добавляем связи с контейнерами.
     */
    public function handleAfterInsert()
    {
        $owner = $this->owner;
        // Если доступ на основании корневого контейнера, то добавляем в связи только его
        if ($owner::$containerAccessType == ActiveRecord::CONTAINER_ACCESS_TYPE_ROOT) {
            $this->_containerIds = [Yii::$app->user->companyId];
        }

        if ($this->_containerIds) {
            $this->_isChanged = true;
            foreach($this->_containerIds as $containerId) {
                // Только если пользователь имеет доступ к контейнеру
                if (php_sapi_name() == 'cli' || in_array($containerId, Yii::$app->user->childrenContainerIds)) {
                    $condition = [
                        'container_id' => $containerId,
                        'model_id'     => $this->owner->modelId->id,
                        'object_id'    => $this->owner->id,
                    ];
                    if (!$containerModelValue = ContainerModelValue::findOne($condition)) {
                        $containerModelValue = new ContainerModelValue($condition);
                        $containerModelValue->save();
                    }
                    unset($containerModelValue);
                }
            }
        }
    }

    /**
     * Удаляем старые и записываем новые связи.
     */
    public function handleAfterUpdate($event = null)
    {
        $idsFromDb = $this->getDBContainerIds();
        $diff = array_diff($idsFromDb, $this->_containerIds) || array_diff($this->_containerIds, $idsFromDb);
        if ($this->_containerIds && $diff) {
            $this->handleAfterDelete();
            $this->handleAfterInsert();
        }
    }

    /**
     * Перед инсертом, апдейтом или валидацией покдючаем валидаторы.
     */
    public function handleBeforeValidate()
    {
        $owner = $this->owner;
        // Добавляем валидатор, если доступ основан на привязке к контейнеру.

        $idsFromDb = $this->getDBContainerIds();
        $diff = array_diff($idsFromDb, $this->_containerIds) || array_diff($this->_containerIds, $idsFromDb);

        if ($diff) {

            if ($this->attachValidator && $this->isValidatorRequired($owner::$containerAccessType) && ($this->owner->isNewRecord || $this->_containerIds)) {
                $owner = $this->owner;
                $validators = $owner->validators;
                $validators->append(Validator::createValidator('app\modules\containers\validators\ContainerIdsValidator', $owner, 'containerIds'));
            }
        }

        // Устанавливаем свойство company_id, если доступ основан на company_id модели
        if ($owner::$containerAccessType == ActiveRecord::CONTAINER_ACCESS_TYPE_COMPANY_ID) {
            $owner->company_id = Yii::$app->user->companyId;
        }
    }

    /**
     * @inheritdoc
     * @param ActiveRecord $owner
     * @throws Exception
     */
    public function attach($owner)
    {
        if (!in_array($owner::$containerAccessType, self::getAccessTypes())) {
            throw new Exception('Укажите тип доступа для контейнерного доступа: ' . $owner::className() . '::containerAccessType');
        }
        parent::attach($owner);
    }

    /**
     * Возвращает список типов доступов для моделей.
     * @return array
     */
    public static function getAccessTypes()
    {
        return [
            ActiveRecord::CONTAINER_ACCESS_TYPE_CHILDREN,
            ActiveRecord::CONTAINER_ACCESS_TYPE_PARENT,
            ActiveRecord::CONTAINER_ACCESS_TYPE_COMPANY_ID,
            ActiveRecord::CONTAINER_ACCESS_TYPE_ROOT
        ];
    }

    /**
     * Функция проверяяет, необходимо ли добавлять ContainerIdsValidator модели на основании контейнерного типа доступа
     * этой модели.
     * @param string $modelAccessType
     * @return bool
     */
    public function isValidatorRequired($modelAccessType)
    {
        return in_array($modelAccessType, [
            ActiveRecord::CONTAINER_ACCESS_TYPE_CHILDREN, ActiveRecord::CONTAINER_ACCESS_TYPE_PARENT
        ]);
    }

    /**
     * @return array|false|null|string
     */
    public function setContainerIds($ids)
    {
        $this->_containerIds = is_array($ids) ? $ids : ($ids ? [$ids] : []);
    }

    /**
     * @return array|false|null|string
     */
    public function getContainerIds()
    {
        if (!$this->_containerIds) {
            $this->_containerIds = $this->getDBContainerIds();
        }
        return $this->_containerIds;
    }

    /**
     * @return array|false|null|string
     */
    public function getDBContainerIds()
    {
        $owner = $this->owner;
        $ids = (new Query())->select('ARRAY_AGG(container_id)')->from('system.container_model_values')
            ->where(['model_id' => $owner::containerModelValueId(), 'object_id' => $owner->id])->scalar();
        $ids = explode(',', trim($ids, '{}'));
        $ids = array_filter($ids, function($val) {return !empty($val);});
        return $ids;
    }

    /**
     * Загружает список связанных структурных единиц модели в containerIds свойство.
     */
    public function loadContainerIds()
    {
        $this->_containerIds = $this->getDBContainerIds();
    }

    /**
     * Определяет, были ли изменены связанные структурные единицы.
     * @return bool
     */

    public function getIsContainerIdsChanged()
    {
        return $this->_isChanged;
    }

    /**
     * Добавляет новые контейнеры в список уже связанных.
     * @param array $ids ID контейнеров
     */
    public function pushContainerIds($ids)
    {
        if (empty($this->_containerIds)) {
            $this->loadContainerIds();
        }
        $ids = is_array($ids) ? : [$ids];
        $ids = array_filter($ids, function($val) {return !empty($val) && is_numeric($val);});
        $this->_containerIds = array_merge($this->_containerIds, $ids);
        $this->_parentContainerIds = array_merge($this->parentContainerIds, $ids);
        $this->_childrenContainerIds = array_merge($this->childrenContainerIds, $ids);
    }

    /**
     * Возвращает список всех родительских контейнеров объекта.
     * @return array
     */
    public function getParentContainerIds()
    {
        if (!$this->_parentContainerIds) {
            if (empty($this->_containerIds)) {
                $this->loadContainerIds();
            }

            $ids = $this->_containerIds;
            $sql = [];
            foreach ($ids as $id) {
                if (!$id) {
                    continue;
                }
                $sql[] = <<<SQL
                (SELECT ARRAY_AGG(c2.id) as ids FROM system.containers c1
                JOIN system.containers c2 ON c2.left_key < c1.left_key AND c2.right_key > c1.right_key AND c2.id != 1  
                WHERE c1.id = $id)
SQL;
            }
            if (count($sql)) {
                $res = Yii::$app->db->createCommand(implode(' UNION ', $sql))->queryAll();
                foreach ($res as $one) {
                    if (!empty($one['ids'])) {
                        $ids = array_merge($ids, explode(',', trim($one['ids'], '{}')));
                    }
                }
            }
            // Добавление корневого контейнера
            array_unshift($ids, 1);
            $ids = array_unique($ids);
            $this->_parentContainerIds = $ids;
        }
        return $this->_parentContainerIds;
    }

    /**
     * Возвращает список всех дочерних контейнеров объекта.
     * @return array
     */
    public function getChildrenContainerIds()
    {
        if (!$this->_childrenContainerIds) {
            if (empty($this->_containerIds)) {
                $this->loadContainerIds();
            }

            $ids = $this->_containerIds;
            $sql = [];
            foreach ($ids as $id) {
                if (!$id) {
                    continue;
                }
                $sql[] = <<<SQL
                (SELECT ARRAY_AGG(c2.id) as ids 
                FROM system.containers c1
                JOIN system.containers c2 ON c2.left_key > c1.left_key AND c2.right_key < c1.right_key 
                WHERE c1.id = $id)
SQL;
            }
            if (count($sql)) {
                $res = Yii::$app->db->createCommand(implode(' UNION ', $sql))->queryAll();
                foreach ($res as $one) {
                    if (!empty($one['ids'])) {
                        $ids = array_merge($ids, explode(',', trim($one['ids'], '{}')));
                    }
                }
            }
            $this->_childrenContainerIds = $ids ? : [0];
        }
        return $this->_childrenContainerIds;
    }

    /**
     * Устанавливает список дочерних контейнеров объекта.
     * @param array $ids
     */
    public function setChildrenContainerIds($ids)
    {
        if (is_array($ids)) {
            $this->_childrenContainerIds = $ids;
        }
    }

    /**
     * Устанавливает список родительских контейнеров объекта.
     * @param array $ids
     */
    public function setParentContainerIds($ids)
    {
        if (is_array($ids)) {
            $this->_parentContainerIds = $ids;
        }
    }
}