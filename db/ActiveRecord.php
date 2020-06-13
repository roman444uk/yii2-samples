<?php

namespace app\modules\system\db;

use app\modules\containers\models\ar\ContainerBelongModel;
use app\modules\containers\models\ar\ContainerModelValue;
use app\modules\containers\models\types\CompanyContainer;
use app\modules\logs\components\LogsManager;
use app\modules\system\helpers\Html;
use app\modules\system\helpers\ModelHelper;
use Yii;
use yii\base\Exception;
use yii\base\Model as YiiBaseModel;
use yii\helpers\ArrayHelper;
use app\modules\containers\models\ar\Container;
use app\modules\containers\models\ar\ContainerType;
use yii\helpers\Url;


/**
 * ActiveRecord Абстрактный класс модели с раширенными возможностями.
 * Class ActiveRecord
 * @package app\db
 *
 * @property ContainerModelValue[] $containerModelValue
 * @property CompanyContainer $company
 * @property Model $modelId
 * @property array $containerIds
 * @property array $parentContainerIds
 * @property array $childrenContainerIds
 * @property array $containerModelValueIds
 * @property string $fullName
 * @property string $name
 * @property string $fullLink
 * @property string $link
 */
abstract class ActiveRecord extends \yii\db\ActiveRecord
{
    const SCENARIO_CREATE = 'create';
    const SCENARIO_UPDATE = 'update';

    /**
     * Модель может прикрепяться ко многим контейнерам. При этом доступ к модели имеют только те контейнеры, которые
     * находятся выше по иерархии.
     */
    const CONTAINER_ACCESS_TYPE_CHILDREN = 'children';

    /**
     * Модель может прикрепяться ко многим контейнерам. При этом доступ к модели имеют только те контейнеры, которые
     * находятся ниже по иерархии.
     */
    const CONTAINER_ACCESS_TYPE_PARENT = 'parent';

    /**
     * Модель прикрепляется к компании свойством company_id в таблице.
     */
    const CONTAINER_ACCESS_TYPE_COMPANY_ID = 'companyId';

    /**
     * Модель прикрепляется только к корневому контейнеру и видна всем доченим структурам.
     */
    const CONTAINER_ACCESS_TYPE_ROOT = 'root';

    const RELATION_HAS_ONE = 'hasOne';
    const RELATION_BELONGS_TO = 'belongsTo';
    const RELATION_HAS_MANY = 'hasMany';
    const RELATION_HAS_MANY_VIA = 'hasManyVia';
    const RELATION_PARAMS = 'params';

    /**
     * Вид контейнерного доступа, используемого для данной модели. Может принимать значения
     * CONTAINER_ACCESS_TYPE_CHILDREN - доступ по дочерним контейнерам и CONTAINER_ACCESS_TYPE_PARENT - доступ по
     * родительским контейнерам и т.д.
     * @var string
     */
    public static $containerAccessType;

    /**
     * @var Model[]
     */
    private static $_models = [];

    /**
     * @var array
     */
    private static $_modelContainerValueIds = [];

    /**
     * @var bool Признак того, что модель создана модулем импорта
     */
    private $_isImportRuntime = false;

    /**
     * @var bool свойство, обозначающее, доступен ли объект текущему пользователю только для чтения
     */
    public $readOnly = false;

    /**
     * @var string свойство, которое хранит в себе имя контейнера. к которому принадлежит объект. Значение сюда записывает
     * поисковая модель \app\modules\system\db\ActiveQuery.
     */
    public $ownerContainer;

    /**
     * @return \app\modules\containers\models\ar\Model[]
     */
    public static function getModels()
    {
        if (empty(self::$_models)) {
            self::_getModels();
        }
        return self::$_models;
    }

    /**
     * @return Model|int|\yii\db\ActiveRecord
     */
    public static function staticModelId()
    {
        $modelName = self::detectBaseClass();
        $models = self::_getModels();
        return isset($models[$modelName]) ? $models[$modelName] : null;
    }

    /**
     * Возвращает ID из таблицы system.models для модели, у которой вызывается данный метод. Испольуется для связывания
     * с таблицей system.container_model_values
     * @return integer
     */
    public static function containerModelValueId()
    {
        $modelName = self::detectBaseClass();
        $models = self::_getModels();
		
        return isset($models[$modelName]) ? $models[$modelName]->id : null;
    }

    /**
     * Возвращает имя модели из таблицы system.models по ID.
     * @param integer $modelId
     * @return string|null
     */
    public static function getModelById($modelId)
    {
        $models = self::_getModels();
        foreach ($models as $modelName => $model) {
            if ($model->id == $modelId) {
                return $modelName;
            }
        }
		
        return null;
    }

    /**
     * Возвращает конфигурационный массив, содержащий описание взаимоотношений данной модели с другими моделями в
     * системе.
     * @return array
     */
    public static function relationConfig()
    {
        $class = substr(static::detectBaseClass(), strrpos(static::detectBaseClass(), '\\') + 1);
        $configFile = Yii::getAlias("@app/modules/logs/config/models/{$class}.php");
		
        return file_exists($configFile) ? require $configFile :  [];
    }

    /**
     * @param $attribute
     * @param null $ids
     * @return array|null|\yii\db\ActiveRecord[]|static
     */
    public function getRelationModels($attribute, $ids = null)
    {
        $config = static::relationConfig();
        if (isset($config[$attribute]) && $data = $config[$attribute]) {
            /** @var ActiveRecord $modelName */
            if (in_array($data['type'], [self::RELATION_HAS_ONE, self::RELATION_BELONGS_TO])) {
                $modelName = $data['class'];
                return $modelName::findOne($this->{$attribute});
            } else if ($data['type'] == self::RELATION_HAS_MANY) {
                return $modelName::find()->where(['in', $attribute, $ids])->indexBy($attribute)->all();
            }
        }
		
        return null;
    }

    /**
     * @return mixed
     */
    public function getContainerModelValueIds()
    {
        if (!isset(self::$_modelContainerValueIds[$this->modelId->id])) {
            $values = $this->containerModelValue;
            self::$_modelContainerValueIds[$this->modelId->id] = [];
            foreach ($values as $value) {
                self::$_modelContainerValueIds[$this->modelId->id][] = $value->container_id;
            }
        }
		
        return self::$_modelContainerValueIds[$this->modelId->id];
    }

    /**
     * @inheritdoc
     */
    public static function find()
    {
        return new ActiveQuery(get_called_class());
    }

    /**
     * @return Model|null|\yii\db\ActiveRecord
     */
    public function getModelId()
    {
        $modelName = self::detectBaseClass();
        $models = self::_getModels();
		
        return isset($models[$modelName]) ? $models[$modelName] : null;
    }

    /**
     * @return $this|null
     */
    public function getContainerModelValue()
    {
        /** @var \yii\db\ActiveRecord $class */
        $class = get_class($this);
        $pk = $class::primaryKey();
        $pk = is_array($pk) ? $pk[0] : $pk;

        if (is_null($pk)) {
            return null;
        }
        return $this->hasMany(ContainerModelValue::className(), ['object_id' => $pk])
            ->where(['model_id' => $this->modelId->id]);
    }
    
    
    /**
     * @return \yii\db\ActiveQuery
     */
    public function getCompany()
    {
        /**
         * Любой контейнер модели, по нему будем определять Компанию
         * @var $container Container
         */
        $container = Container::find()->andWhere([Container::tableName() . '.id' => $this->containerIds])->one();
        
        if ($container) {
            $companyId = $container->parents($container->level - 1)->andWhere(['level' => 1])->select('id')->column();
            $this->containerIds = ArrayHelper::merge($this->containerIds, $companyId);
        }
    
        return $this->hasOne( CompanyContainer::className(), ['id' => 'containerIds']);
    }

    /**
     * @inheritdoc
     */
    public static function instantiate($row)
    {
        return new static($row);
    }

    /**
     * Возвращает признак того, что модель создана модулем импорта
     * @return bool
     */
    public function isImportRuntime()
    {
        return $this->_isImportRuntime;
    }

    /**
     * Устанавливает признак того, что модель создана модулем импорта
     */
    public function setIsImportRuntime()
    {
        $this->_isImportRuntime = true;
    }

    /**
     * Добавляет в список активных атрибутов 'containerIds', если включено ContainerRelationArBehavior для того,
     * чтобы сработал \app\modules\containers\validators\ContainerIdsValidator.
     * @inheritdoc
     */
    public function activeAttributes()
    {
        $attributes = parent::activeAttributes();
        if ($this->getBehavior('containerRelation')) {
            $attributes[] = 'containerIds';
        }
		
        return $attributes;
    }

    /**
     * @inheritdoc
     */
    public function safeAttributes()
    {
        return array_merge(parent::safeAttributes(), ['containerIds']);
    }

    /**
     * @return \app\modules\containers\models\ar\Models[]|array|\yii\db\ActiveRecord[]
     */
    private static function _getModels()
    {
        if (empty(self::$_models)) {
            self::$_models = Model::find()->indexBy('name')->all();
        }
		
        return self::$_models;
    }

    /**
     * @inheritdoc
     */
    public function fields()
    {
        return ArrayHelper::merge(parent::fields(), ['errors']);
    }

    /**
     * Подменяет таблицу представлением, если установлен параметр времени текущего состояния системы (логирования).
     * @param string $fullTableName
     * @return string
     */
    public static function modifyTableName($fullTableName)
    {
        if (php_sapi_name() != 'cli' && ($postfix = LogsManager::getTablePostfix())) {
            return LogsManager::modifyTableName($fullTableName, $postfix);
        }
		
        return $fullTableName;
    }

    /**
     * @return string
     */
    public static function detectBaseClass()
    {
        $calledClass = get_called_class();
		
        return defined("{$calledClass}::BASE_CLASS") ? $calledClass::BASE_CLASS : $calledClass;
    }

    public static function createMultiple($modelClass, $multipleModels = null, $data = [])
    {
        $model = new $modelClass;
        /* @var $model YiiBaseModel */

        $formName = $model->formName();
        $post = isset($data[$formName]) ? $data[$formName] : [];
        $models = [];
        $flag = false;

        if ($multipleModels !== null && is_array($multipleModels) && !empty($multipleModels)) {
            $keys = array_keys(ArrayHelper::map($multipleModels, 'id', 'id'));
            $multipleModels = array_combine($keys, $multipleModels);
            $flag = true;
        }

        if ($post && is_array($post)) {
            foreach ($post as $i => $item) {
                if ($flag) {
                    if (isset($item['id']) && !empty($item['id']) && isset($multipleModels[$item['id']])) {
                        $models[] = $multipleModels[$item['id']];
                    } else {
                        $models[] = new $modelClass;
                    }
                } else {
                    $models[] = new $modelClass;
                }
            }
        }
        unset($model, $formName, $post);
		
        return $models;
    }

    /**
     * @return array
     */
    public function getDirtyRelatedAttributes()
    {
        return [];
    }

    /**
     * @inheritdoc
     * @param boolean $strictMode строгое сравнение
     */
    public function getDirtyAttributes($names = null, $strictMode = true)
    {
        $dirtyAttributes = parent::getDirtyAttributes($names = null);
        $oldAttributes = $this->getOldAttributes();
		
        if (!$strictMode) {
            foreach ($dirtyAttributes as $attribute => $value) {
                if (array_key_exists($attribute, $oldAttributes) && $value == $oldAttributes[$attribute]) {
                    unset($dirtyAttributes[$attribute]);
                }
            }
        }
		
        return $dirtyAttributes;
    }

    /**
     * URL к объекту.
     * @param bool $absolute
     * @return string
     */
    public function getLink($absolute = true)
    {
        return ModelHelper::link($this, null, $absolute);
    }

    /**
     * @return string
     */
    public function getFullName()
    {
        return ModelHelper::fullName($this);
    }

    /**
     * @param array $modelData
     * @return string
     */
    public static function fullName($modelData)
    {
        return ModelHelper::fullName(static::detectBaseClass(), $modelData);
    }

    /**
     * @return string
     */
    public function getFullLink()
    {
        return ModelHelper::fullLink($this);
    }

    /**
     * @param array $modelData
     * @return string
     */
    public static function fullLink($modelData)
    {
        return ModelHelper::fullLink(static::detectBaseClass(), $modelData);
    }

    /**
     * Returns the list of labels for specified attribute or label of attribute if value param is passed.
     * @param string $attribute attribute name
     * @param mixed $value attribute value
     * @return array|string
     */
    public static function attributeValueLabels($attribute, $value = null)
    {
        $labels = static::attributeValueLabelsConfig();
        if (isset($labels[$attribute])) {
            if ($value !== null) {
                return isset($labels[$attribute][$value]) ? $labels[$attribute][$value] : $value;
            } else {
                return $labels[$attribute];
            }
        }
		
        return $value;
    }

    /**
     * Возаращает контейнер в зависимости от типа интерфейса.
     * @param string $interfaceType
     * @return mixed
     */
    protected function getContainerByInterface($interfaceType)
    {
        return $this->hasOne(Container::className(), ['id' => 'container_id'])
            ->viaTable(ContainerModelValue::tableName(), ['object_id' => 'id'])
            ->innerJoinWith(['containerModelValues', 'type'])
            ->andWhere([
                ContainerModelValue::tableName() . ".model_id" => self::containerModelValueId(),
                ContainerType::tableName() . ".system_interface" => $interfaceType
            ]);
    }

    /**
     * Возвращает принадлежность к контейнру в зависмости от типа интерфейса
     * @param string $interfaceType
     * @return mixed
     */
    protected function getContainerBelongByInterface($interfaceType)
    {
        $viaTableAlias = 'container_belongs_models_' . $interfaceType;
        $typeTableAlias = 'type_' . $interfaceType;
		
        return $this->hasOne(Container::className(), ['id' => 'container_id'])
            ->viaTable(ContainerBelongModel::tableName() . " " . $viaTableAlias, ['object_id' => 'id'],
                function ($query) use ($viaTableAlias) {
                    /* @var $query \yii\db\ActiveQuery */
                    $query->andWhere([$viaTableAlias . ".model_id" => self::containerModelValueId()]);
                }
            )
            ->joinWith(['type' => function ($query) use ($typeTableAlias, $interfaceType) {
                /* @var $query \yii\db\ActiveQuery */
                $query->from(ContainerType::tableName() . ' ' . $typeTableAlias);
                $query->select($typeTableAlias . '.*');
                $query->andWhere([$typeTableAlias . ".system_interface" => $interfaceType]);
            }])
			->alias($interfaceType);
    }

    /**
     * Configuration for attributeValueLabels() function.
     * @return array
     */
    public static function attributeValueLabelsConfig()
    {
        return [];
    }

    /**
     * @return string
     */
    public function getStringErrors()
    {
        $errorsText = '';
        foreach ($this->getErrors() as $errors) {
            foreach ($errors as $error) {
                $errorsText .= $error;
            }
        }
		
        return $errorsText;
    }
}