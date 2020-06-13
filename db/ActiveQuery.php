<?php

namespace app\modules\system\db;

use app\modules\logs\LogsModule;
use yii\db\ActiveQuery as BaseActiveQuery;
use Yii;
use yii\db\Expression;
use yii\db\Query;

/**
 * Class ActiveQuery поисковая модель, добавляющая функционал для работы с доступами к объектам.
 * @package app\modules\system\db
 */
class ActiveQuery extends BaseActiveQuery
{
    /**
     * @var string
     */
    public $tableName;

    /**
     * @var integer ID модели из таблицы models
     */
    public $modelId;

    /**
     * @var bool нужно ли добавлять условия контейнерного доступа
     */
    protected $_addAccessConditions = false;

    /**
     * @var array
     */
    protected $_accessConditions = [];

    /**
     * @var array список досупных контейнеров
     */
    protected $_containerIds = [0];

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();
		
        /* @var $modelClass ActiveRecord */
        $modelClass = $this->modelClass;
        $this->tableName = $modelClass::tableName();
        $this->modelId = $modelClass::containerModelValueId();
        $this->select = ["{$this->tableName}.*"];
    }

    /**
     * @param $ids
     * @return $this
     */
    public function addAccessConditions($ids = null)
    {
        if (Yii::$app->user->checkDemeanor('Ignore containers')) {
            $ids = Yii::$app->db->createCommand('SELECT id FROM system.containers')->queryColumn();
        }
		
        $this->_addAccessConditions = true;
		
        if (is_numeric($ids)) {
            $this->_containerIds = [$ids];
        } else if (!$ids) {
            $this->_containerIds = Yii::$app->user->childrenContainerIds;
        } else {
            $this->_containerIds = $ids;
        }
		
        return $this;
    }

    /**
     * Добавление в поисковую модель условий временного и индивидуального доступа к объектам.
     * @return \app\modules\system\db\ActiveQuery
     */
    protected function addObjectAccessConditions()
    {
        $this->_accessConditions[] = ['and',
            ['oa.user_id' => Yii::$app->user->id],
            ['or', ['oa.temporary' => '0'], ['>', 'oa.until_time', new Expression('NOW()')]]
        ];
		
        return $this->leftJoin('system."object_access" "oa"', "oa.object_id = {$this->tableName}.id AND oa.model_id = $this->modelId");
    }

    /**
     * Добавление в поисковую модель условий контейнерного доступа по родительской ветке.
     * @param integer|array $parentIds ID контейнерa или их список, по родителям которых производится поиск
     * @return \app\modules\system\db\ActiveQuery
     */
    protected function addParentBranchConditions($parentIds = null)
    {
        // выборка ветки родительских контейнеров для кoнтейнера с ID = $id
        // в запрос войдут только те записи, которые принадлежат к какому либо из этих контейнеров
        if ($parentIds != null) {
            $parentIds = is_array($parentIds) ? implode(',', $parentIds) : $parentIds;
            $parentBranchIds = <<<SQL
                SELECT DISTINCT c1.id FROM system.containers c1
                INNER JOIN system.containers c2 ON "c1"."left_key" <= "c2".left_key AND "c1"."right_key" >= "c2".right_key AND "c1"."id" != 1
                WHERE "c2"."id" IN($parentIds)
SQL;
        } else {
            $parentBranchIds = <<<SQL
                SELECT DISTINCT c1.id FROM system.containers c1
                INNER JOIN system.containers c2 ON "c1"."left_key" <= "c2".left_key AND "c1"."right_key" >= "c2".right_key AND "c1"."id" != 1
SQL;
        }
		
        $this->_accessConditions[] = "cmv.container_id IN({$parentBranchIds})";
		
        // объединение с целью узнать, какие объекты отображать
        return $this
            ->leftJoin('system."container_model_values" "cmv"', "cmv.object_id = {$this->tableName}.id AND cmv.model_id = {$this->modelId}");
    }

    /**
     * Добавление в поисковую модель условий контейнерного доступа по дочерним веткам.
     * @param array $ids список ID контейнеров, к которым привязан пользователь
     * @return \app\modules\system\db\ActiveQuery
     */
    protected function addChildrenBranchConditions($ids)
    {
        if (!empty($ids)) {
            $this->_accessConditions[] = ['in', 'cmv.container_id', $ids];
            $this->leftJoin('system.container_model_values cmv', "cmv.object_id = {$this->tableName}.id AND cmv.model_id = {$this->modelId}");
        } else {
            $this->where('0=1');
        }

        return $this;
    }

    /**
     * Добавление в поисковую модель условий доступа к объектам на основе принадлежности в компании, записанной в
     * свойство company_id.
     */
    protected function addCompanyIdConditions()
    {
        $this->_accessConditions[] = ["{$this->tableName}.company_id" => Yii::$app->user->companyId];
		
        return $this;
    }

    /**
     * @return $this
     */
    protected function addRootConditions()
    {
        $this->_accessConditions[] = ['cmv.container_id' => Yii::$app->user->companyId];
		
        return $this->leftJoin('system.container_model_values cmv', "cmv.object_id = {$this->tableName}.id AND cmv.model_id = $this->modelId");
    }

    /**
     * @param array $ids
     * @return $this
     */
    protected function addContainerSelectColumns($ids = null)
    {
        $select = [];
        if (!empty($this->select) and $this->select[0] !== 'COUNT(*)') {
            $select = [
                "DISTINCT ON ({$this->tableName}.id) owner.name AS \"ownerContainer\""
                . ", CASE WHEN cmv.container_id IN (" . implode(',', $ids) . ") THEN false WHEN oa.access_type = 'write' THEN false ELSE true END AS \"readOnly\""
            ];
        }
		
        return $this
            ->innerJoin('system.containers owner', "owner.id = cmv.container_id")
            ->select(array_merge($select, $this->select));
    }

    /**
     * @inheritdoc
     */
    public function createCommand($db = null)
    {
        if ($this->_addAccessConditions) {
            /** @var $modelClass ActiveRecord */
            $modelClass = $this->modelClass;
			
            // Добавление специфических для модели условий
            switch ($modelClass::$containerAccessType) {
                case $modelClass::CONTAINER_ACCESS_TYPE_CHILDREN:
                    $this->addChildrenBranchConditions($this->_containerIds);
                    break;
                case $modelClass::CONTAINER_ACCESS_TYPE_PARENT:
                    $this->addParentBranchConditions($this->_containerIds);
                    break;
                case $modelClass::CONTAINER_ACCESS_TYPE_COMPANY_ID:
                    $this->addCompanyIdConditions();
                    break;
                case $modelClass::CONTAINER_ACCESS_TYPE_ROOT:
                    $this->addRootConditions();
                   break;
            }
			
            // Добавление условий индивидуального доступа
            if (in_array($modelClass::$containerAccessType, [
                $modelClass::CONTAINER_ACCESS_TYPE_CHILDREN, $modelClass::CONTAINER_ACCESS_TYPE_PARENT
            ])) {
                $this->addObjectAccessConditions()->addContainerSelectColumns($this->_containerIds);
            }
			
            if (!empty($this->_accessConditions)) {
                array_unshift($this->_accessConditions, 'or');
                $this->andWhere($this->_accessConditions);
            }
        }
		
        return parent::createCommand($db);
    }

    /**
     * @inheritdoc
     */
    public function alias($alias)
    {
        // Замена имени таблицы алиасом
        foreach ($this->select as $key => $value) {
            if ($value == str_replace('"', '', "{$this->tableName}.*")) {
                $this->select[$key] = "{$alias}.*";
            }
        }
		
        $this->tableName = $alias;
		
        return parent::alias($alias);
    }
}