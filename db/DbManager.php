<?php

namespace app\modules\system\db;

use Yii;
use yii\base\Exception;
use yii\base\InvalidCallException;
use yii\base\InvalidConfigException;
use yii\base\InvalidParamException;
use yii\db\Query;
use yii\rbac\Rule;
use app\modules\rbac\models\Assignment;
use app\modules\rbac\models\Demeanor;
use app\modules\rbac\models\Permission;
use app\modules\rbac\models\Role;
use app\modules\rbac\models\AuthItem;
use app\modules\rbac\components\Item;

class DbManager extends \yii\rbac\DbManager
{
    /**
     * @var string the name of the table storing authorization items.
     */
    public $itemTable = 'system.auth_item';
	
    /**
     * @var string the name of the table storing authorization item hierarchy.
     */
    public $itemChildTable = 'system.auth_item_child';
	
    /**
     * @var string the name of the table storing authorization item assignments.
     */
    public $assignmentTable = 'system.auth_assignment';
	
    /**
     * @var string the name of the table storing rules.
     */
    public $ruleTable = 'system.auth_rule';
	
    /**
     * @var array
     */
    protected $itemsModelsIds;
	
    /**
     * @var integer ID текущего пользователя
     */
    protected $userId;

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();

        if (php_sapi_name() != 'cli') {
            $this->itemsModelsIds = [Role::containerModelValueId(), Permission::containerModelValueId(), Demeanor::containerModelValueId()];
        }

        $this->userId = Yii::$app->user->id;
    }

    /**
     * @inheritdoc
     */
    public function getItems($type = null, $excludeItems = [])
    {
        $modelIds = implode(',', [Role::containerModelValueId(), Permission::containerModelValueId()]);
        $containerIds = implode(',', Yii::$app->user->parentContainerIds);
        $excludedNames = "'" . implode("','", $excludeItems + ['']) . "'";

        $rows = $this->db->createCommand(<<<SQL
            SELECT ai.* FROM {$this->itemTable} ai
            INNER JOIN system.container_model_values cmv ON cmv.object_id = ai.id AND model_id IN ($modelIds)
            INNER JOIN system.containers c ON c.id = cmv.container_id
            WHERE cmv.container_id IN ($containerIds) AND ai.name NOT IN ($excludedNames) AND ai.type = '$type'
            ORDER BY ai.description 
SQL
        )->queryAll();

        $items = [];
        foreach ($rows as $row) {
            $items[$row['name']] = $this->populateItem($row);
        }

        return $items;
    }

    /**
     * @param integer|string $itemName ID или имя сущности
     * @return null|\app\modules\rbac\models\AuthItem
     */
    public function getItem($itemName)
    {
        if (empty($itemName)) {
            return null;
        }

        if (!empty($this->items[$itemName])) {
            return $this->items[$itemName];
        }

        if (is_numeric($itemName)) {
            if ($row = (new Query())->select('*')->from($this->itemTable)->where(['id' => $itemName])->one()) {
                return $this->populateItem($row);
            }
        }

        $modelIds = implode(',', [Role::containerModelValueId(), Permission::containerModelValueId()]);
        $row = (new Query())
            ->select('ai.*')
            ->from($this->itemTable . ' "ai"')
            ->innerJoin('system."container_model_values" "cmv"', '"cmv"."object_id" = "ai"."id" AND "cmv"."model_id" IN (' . $modelIds . ')')
            ->innerJoin('system."containers" "c1"', '"c1"."id" = "cmv"."container_id"')
            ->innerJoin('system."containers" "c2"', '"c1"."left_key" <= "c2"."left_key" AND "c1"."right_key" >= "c2"."right_key"')
            ->where('"ai"."name" = :name')
            ->andWhere(['in', '"c2"."id"', Yii::$app->user->containerIds])
            ->addParams(['name' => $itemName])
            ->one();

        if ($row === false) {
            return null;
        }

        if (!isset($row['data']) || ($data = @unserialize($row['data'])) === false) {
            $row['data'] = null;
        }

        return $this->populateItem($row);
    }

    /**
     * Возвращает список сущностей (ролей, прав и поведений), назначенных пользователю.
     * @param integer $userId ID пользователя
     * @return \app\modules\rbac\models\AuthItem[]
     */
    public function getItemsByUser($userId)
    {
        if (empty($userId)) {
            return [];
        }

        $rows = (new Query)
            ->select('"ai".*')
            ->from($this->itemTable . ' "ai"')
            ->innerJoin($this->assignmentTable . ' "aa"', ['"aa"."item_id"' => '"ai"."id"'])
            ->where(['"aa"."user_id"' => $userId])
            ->all();

        $items = [];
        foreach ($rows as $row) {
            $items[$row['id']] = $this->populateItem($row);
        }

        return $items;
    }

    /**
     * @inheritdoc
     */
    public function getPermissionsByRole($roleId)
    {
        if (empty($roleId)) {
            return [];
        }

        $rows = (new Query)
            ->select('"child".*')
            ->from($this->itemTable . '"child"')
            ->innerJoin($this->itemChildTable . ' "aic"', '"aic"."child_id" = "child"."id"')
            ->innerJoin($this->itemTable . ' "parent"', '"aic"."parent_id" = "parent"."id"')
            ->where(['"aic"."parent_id"' => $roleId])
            ->all();

        $items = [];
        foreach ($rows as $row) {
            $items[$row['id']] = $this->populateItem($row);
        }

        return $items;
    }

    /**
     * Создание экземпляра поведения.
     * @param string $name Имя поведения
     * @return \app\modules\rbac\models\Demeanor
     */
    public function createDemeanor($name)
    {
        return new Demeanor(['name' => $name]);
    }

    /**
     * @inheritdoc
     */
    public function createRole($name)
    {
        return new Role(['name' => $name]);
    }

    /**
     * @inheritdoc
     */
    public function createPermission($name)
    {
        return new Permission(['name' => $name]);
    }

    /**
     * @inheritdoc
     */
    public function getChildren($itemId)
    {
        if (empty($itemId)) {
            return [];
        }

        $rows = (new Query)
            ->select('"ai".*')
            ->from($this->itemTable . ' "ai"')
            ->innerJoin($this->itemChildTable . ' "aic"', '"aic"."child_id" = "ai"."id"')
            ->where(['"aic"."parent_id"' => $itemId])
            ->all();

        $children = [];
        foreach ($rows as $row) {
            $children[$row['id']] = $this->populateItem($row);
        }

        return $children;
    }

    /**
     * @inheritdoc
     */
    public function getChildrenIds($itemId)
    {
        if (empty($itemId)) {
            return [];
        }

        $rows = (new Query)
            ->select('"ai".id')
            ->from($this->itemTable . ' "ai"')
            ->innerJoin($this->itemChildTable . ' "aic"', '"aic"."child_id" = "ai"."id"')
            ->where(['"aic"."parent_id"' => $itemId])
            ->all();

        $children = [];
        foreach ($rows as $row) {
            $children[] = $row['id'];
        }

        return $children;
    }

    /**
     * @inheritdoc
     */
    protected function getChildrenList()
    {
        $query = (new Query)
            ->select('"aip"."name" as parent, "aic"."name" as child')
            ->from($this->itemChildTable . ' "ai"')
            ->innerJoin($this->itemTable . ' "aip"', '"aip"."id" = "ai"."parent_id"')
            ->innerJoin($this->itemTable . ' "aic"', '"aic"."id" = "ai"."child_id"');

        $parents = [];
        foreach ($query->all($this->db) as $row) {
            $parents[$row['parent']][] = $row['child'];
        }
        return $parents;
    }

    /**
     * @inheritdoc
     */
    public function addChild($parent, $child)
    {
        if ($parent->id === $child->id) {
            throw new InvalidParamException("Cannot add '{$parent->name}' as a child of itself.");
        }

        if ($parent instanceof Permission && $child instanceof Role) {
            throw new InvalidParamException('Cannot add a role as a child of a permission.');
        }

        if ($this->detectLoop($parent, $child)) {
            throw new InvalidCallException("Cannot add '{$child->name}' as a child of '{$parent->name}'. A loop has been detected.");
        }

        $this->db->createCommand()
            ->insert($this->itemChildTable, ['parent_id' => $parent->id, 'child_id' => $child->id])
            ->execute();

        $this->invalidateCache();

        return true;
    }

    /**
     * @inheritdoc
     */
    public function removeChild($parent, $child)
    {
        $result = $this->db->createCommand()
                ->delete($this->itemChildTable, ['parent_id' => $parent->id, 'child_id' => $child->id])
                ->execute() > 0;

        $this->invalidateCache();

        return $result;
    }

    /**
     * @inheritdoc
     * @throws Exception
     */
    protected function populateItem($row)
    {
        switch ($row['type']) {
            case Item::TYPE_PERMISSION:
                $class = Permission::className();
                break;

            case Item::TYPE_ROLE:
                $class = Role::className();
                break;

            case Item::TYPE_DEMEANOR:
                $class = Demeanor::className();
                break;

            default:
                throw new Exception('There is no permission of such type.');
        }

        if (!isset($row['data']) || ($data = @unserialize($row['data'])) === false) {
            $data = null;
        }

        return new $class($row);
    }

    /**
     * @inheritdoc
     * Если в $params передан параметр 'roleId', то будет производиться поиск назначенных пользователю сущностей по другому
     * алгоритму. Смотри $this->getAssignmentsByRole().
     */
    public function checkAccess($userId, $permissionName, $params = [])
    {
        $this->loadFromCache();
        $roleId = $params['roleId'];

        // Проверка разрешения через кешированные сущности.
        if ($roleId && $userId == $this->userId) {
            if (array_key_exists($permissionName, $this->items[Item::TYPE_PERMISSION])) {
                $permission = $this->items[Item::TYPE_PERMISSION][$permissionName];
                return $permission['direct'] || in_array($roleId, $permission['inherited']);
            } else {
                return false;
            }
        }

        if (isset($roleId)) {
            $assignments = $this->getAssignmentsByRole($userId, $roleId);
        } else {
            $assignments = $this->getAssignments($userId);
        }

        // Если нет такого разрешения, то проверка возвращает отрицательный результат
        if (!$permissionId = (new Query())->select('id')->from($this->itemTable)->where([
            'type' => Item::TYPE_PERMISSION, 'name' => $permissionName
        ])->scalar()) {
            return false;
        }

        $this->loadFromCache();
        if ($this->items !== null) {
            return $this->checkAccessFromCache($userId, $permissionId, $params, $assignments);
        } else {
            return $this->checkAccessRecursive($userId, $permissionId, $params, $assignments);
        }
    }

    /**
     * Проверка, есть ли у пользователя поведение.
     * @param integer $userId ID пользователя
     * @param string $name имя поведения
     * @param integer $roleId имя роли
     * @return bool
     */
    public function checkDemeanor($userId, $name, $roleId = null)
    {
        $this->loadFromCache();

        // Проверка поведения через кешированные сущности.
        if ($roleId && $userId == $this->userId) {
            if (array_key_exists($name, $this->items[Item::TYPE_DEMEANOR])) {
                $demeanor = $this->items[Item::TYPE_DEMEANOR][$name];
                return $demeanor['direct'] || in_array($roleId, $demeanor['inherited']);
            } else {
                return false;
            }
        }

        return $this->checkItemAccess($userId, $name, Item::TYPE_DEMEANOR, $roleId);
    }

    /**
     * Проверка, есть ли у пользователя поведение.
     * @param integer $userId ID пользователя
     * @param string $name имя роли
     * @return bool
     */
    public function checkRole($userId, $roleId)
    {
        $this->loadFromCache();

        // Проверка наличия роли через кешированные сущности.
        if ($roleId && $userId == $this->userId) {
            return array_key_exists($roleId, $this->items[Item::TYPE_ROLE]);
        }

        return isset($this->roles[$roleId]);
    }

    /**
     * Проверка, доступна ли пользователю сущность.
     * @param $userId ID пользователя
     * @param $itemName имя сущности
     * @param $itemType тип сущности
     * @return bool
     */
    protected function checkItemAccess($userId, $itemName, $itemType, $role = null)
    {
        if (!$userId || !$itemName) {
            return false;
        }
        $roleCondition = $role ? "AND ai2role.id = '$role'" : '';
        return (boolean) (new Query())
            ->select('system.users.id')
            ->from('system.users')
            ->leftJoin(['aa1' => Assignment::tableName()], "aa1.user_id = system.users.id")
            ->leftJoin(['ai1' => AuthItem::tableName()], "ai1.id = aa1.item_id AND ai1.type = {$itemType}")
            ->leftJoin(['aa2' => Assignment::tableName()], "aa2.user_id = system.users.id")
            ->leftJoin(['ai2role' => AuthItem::tableName()], "aa2.item_id = ai2role.id AND ai2role.type = " . Item::TYPE_ROLE)
            ->leftJoin(['aic2' => 'system.auth_item_child'], "aic2.parent_id = ai2role.id")
            ->leftJoin(['ai2dem' => AuthItem::tableName()], "aic2.child_id = ai2dem.id AND ai2dem.type = {$itemType}")
            ->andWhere("system.users.id = {$userId} AND (ai1.name = '{$itemName}' OR ai2dem.name = '{$itemName}') $roleCondition")
            ->one();
    }

    /**
     * Расширенная функция getAssignments, ищет все назначенные пользователю сущности, исключая все роли, кроме
     * той, которую использует пользователь в данный момент.
     * @param int $userId ID пользователя
     * @param string $roleId ID ролb, которую в данный момент использует пользователь
     * @return array
     */
    public function getAssignmentsByRole($userId, $roleId)
    {
        if (empty($userId)) {
            return [];
        }

        $typeRole = Item::TYPE_ROLE;
        return Assignment::find()->alias('aa')->with('item')
            ->join('JOIN', $this->itemTable . ' ai', 'ai.id = aa.item_id')
            ->where("aa.user_id = {$userId} AND (ai.id = {$roleId} OR ai.type != {$typeRole})")
            ->indexBy('item_id')
            ->all();
    }


    /**
     * Возвращает поведение по названию.
     * @param integer $id ID поведения
     * @return \app\modules\rbac\models\Demeanor|null поведение с соответствующим именем
     */
    public function getDemeanor($id)
    {
        $item = $this->getItem($id);
        return $item instanceof Item && $item->type == Item::TYPE_DEMEANOR ? $item : null;
    }

    /**
     * @inheritdoc
     */
    public function getPermission($id)
    {
        $item = $this->getItem($id);
        return $item instanceof Item && $item->type == Item::TYPE_PERMISSION ? $item : null;
    }

    /**
     * @inheritdoc
     */
    public function getRole($id)
    {
        $item = $this->getItem($id);
        return $item instanceof Item && $item->type == Item::TYPE_ROLE ? $item : null;
    }

    /**
     * @inheritdoc
     */
    public function assign($itemId, $userId)
    {
        $assignment = new Assignment([
            'user_id' => $userId,
            'item_id' => $itemId,
            'created_at' => time(),
        ]);

        $this->db->createCommand()
            ->insert($this->assignmentTable, [
                'user_id' => $assignment->user_id,
                'item_id' => $assignment->item_id,
                'created_at' => $assignment->created_at,
            ])->execute();

        return $assignment;
    }

    /**
     * @inheritdoc
     */
    public function getAssignments($userId)
    {
        if (empty($userId)) {
            return [];
        }

        $return = [];
        $models = Assignment::find()->with('item')->where(['user_id' => $userId])->all();
        foreach ($models as $model) {
            $return[$model->name] = $model;
        }
        return $return;
    }

    /**
     * @inheritdoc
     */
    public function getRolesByUser($userId)
    {
        if (!isset($userId) || $userId === '') {
            return [];
        }

        $rows = (new Query)
            ->select('"ai".*')
            ->from($this->assignmentTable . ' "aa"')
            ->innerJoin($this->itemTable . ' "ai"', '"ai"."id" = "aa"."item_id"')
            ->where(['"ai"."type"' => Item::TYPE_ROLE, '"aa"."user_id"' => $userId])
            ->all();

        $roles = [];
        foreach ($rows as $row) {
            $roles[$row['id']] = $this->populateItem($row);
        }
        return $roles;
    }

    /**
     * @inheritdoc
     */
    public function getPermissionsByUser($userId)
    {
        if (empty($userId)) {
            return [];
        }

        $directPermission = $this->getDirectPermissionsByUser($userId);
        $inheritedPermission = $this->getInheritedPermissionsByUser($userId);

        return array_merge($directPermission, $inheritedPermission);
    }

    /**
     * Возвращает все назначенные пользователю права.
     * @param integer $userId ID пользователя
     * @return \app\modules\rbac\models\Permission[]
     */
    public function getDirectUserPermissions($userId)
    {
        return $this->getDirectPermissionsByUser($userId);
    }

    /**
     * @inheritdoc
     */
    protected function getDirectPermissionsByUser($userId)
    {
        $rows = (new Query)
            ->select('"ai".*')
            ->from($this->assignmentTable . ' "aa"')
            ->innerJoin($this->itemTable . ' "ai"', '"ai"."id" = "aa"."item_id"')
            ->where(['"ai"."type"' => Item::TYPE_PERMISSION, '"aa"."user_id"' => $userId])
            ->all();

        $permissions = [];
        foreach ($rows as $row) {
            $permissions[$row['id']] = $this->populateItem($row);
        }

        return $permissions;
    }

    /**
     * @inheritdoc
     */
    protected function getInheritedPermissionsByUser($userId)
    {
        $query = (new Query)->select('ai.name as item_name')
            ->from($this->assignmentTable . ' "aa"')
            ->innerJoin($this->itemTable . ' "ai"', '"ai"."id" = "aa"."item_id"')
            ->where(['user_id' => (string) $userId]);

        $childrenList = $this->getChildrenList();
        $result = [];
        foreach ($query->column($this->db) as $roleName) {
            $this->getChildrenRecursive($roleName, $childrenList, $result);
        }

        if (empty($result)) {
            return [];
        }

        $query = (new Query)->from($this->itemTable)->where([
            'type' => Item::TYPE_PERMISSION,
            'name' => array_keys($result),
        ]);
        $permissions = [];
        foreach ($query->all($this->db) as $row) {
            $permissions[$row['id']] = $this->populateItem($row);
        }
        return $permissions;
    }

    /**
     * @inheritdoc
     */
    protected function executeRule($user, $item, $params)
    {
        if ($item->rule_name === null) {
            return true;
        }
        $rule = $this->getRule($item->rule_name);
        if ($rule instanceof Rule) {
            return $rule->execute($user, $item, $params);
        } else {
            throw new InvalidConfigException("Rule not found: {$item->rule_name}");
        }
    }

    /**
     * @inheritdoc
     */
    protected function detectLoop($parent, $child)
    {
        if ($child->id === $parent->id) {
            return true;
        }
        foreach ($this->getChildren($child->id) as $grandchild) {
            if ($this->detectLoop($parent, $grandchild)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @inheritdoc
     */
    protected function checkAccessRecursive($user, $itemId, $params, $assignments)
    {
        if (isset($assignments[$itemId]) || in_array($itemId, $this->defaultRoles)) {
            return true;
        }

        $modelIds = implode(',', [Role::containerModelValueId(), Permission::containerModelValueId()]);
        $containerIds = implode(',', Yii::$app->user->parentContainerIds);
        $parents = $this->db->createCommand(<<<SQL
            SELECT parent.id  FROM {$this->itemChildTable} aic
            INNER JOIN {$this->itemTable} child ON aic.child_id = child.id
            INNER JOIN {$this->itemTable} parent ON aic.parent_id = parent.id
            INNER JOIN system.container_model_values cmv ON cmv.object_id = parent.id AND model_id IN ($modelIds)
            INNER JOIN system.containers c ON c.id = cmv.container_id
            WHERE child.id = '{$itemId}' AND cmv.container_id IN ($containerIds)
SQL
        )->queryAll();

        foreach ($parents as $parent) {
            if ($this->checkAccessRecursive($user, $parent['id'], $params, $assignments)) {
                return true;
            }
        }

        return false;
    }
    
    /**
     * Проверяет существет ли разрешение в БД
     *
     * @param $permissionName
     *
     * @return bool
     */
    public function checkPermissionExist($permissionName)
    {
        
        return (new Query())->from($this->itemTable)->where([
            'type' => Item::TYPE_PERMISSION, 'name' => $permissionName,
        ])->exists()
            ;
    }

    /**
     * @inheritdoc
     */
    public function removeChildren($parent)
    {
        $result = $this->db->createCommand()
                ->delete($this->itemChildTable, ['parent_id' => $parent->id])
                ->execute() > 0;

        $this->invalidateCache();

        return $result;
    }

    /**
     * Вовращает все сущности, которые прикреплены к пользователю напрямую.
     * @param array $types тип сущности
     * @return array
     */
    public function getDirectUserAssignments($userId, $types = null)
    {
        if (!is_array($types)) {
            $types = [Item::TYPE_DEMEANOR, Item::TYPE_ROLE, Item::TYPE_PERMISSION];
        }
        $rows = (new Query)
            ->select('"ai".*')
            ->from($this->assignmentTable . ' "aa"')
            ->innerJoin($this->itemTable . ' "ai"', '"ai"."id" = "aa"."item_id"')
            ->where(['and',
                ['in', 'ai.type', $types],
                ['=', 'aa.user_id', $userId]
            ])
            ->all();

        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->populateItem($row);
        }

        return $items;
    }

    /**
     * @inheritdoc
     */
    public function loadFromCache()
    {
        if ($this->items != null || !$this->userId) {
            return;
        }

        $items = $this->db->createCommand(<<<SQL
          (
              SELECT ai.id, ai.name, ai.type, TRUE::boolean AS direct, NULL AS inherited FROM {$this->itemTable} ai
              INNER JOIN {$this->assignmentTable} aa ON aa.item_id = ai.id
              WHERE aa.user_id = {$this->userId}
          ) UNION (
              SELECT child.id, child.name, child.type, FALSE::boolean AS direct, parent.id AS inherited FROM {$this->itemTable} child
              INNER JOIN {$this->itemChildTable} aic ON aic.child_id = child.id
              INNER JOIN {$this->itemTable} parent ON parent.id = aic.parent_id
              INNER JOIN {$this->assignmentTable} aa ON aa.item_id = parent.id
              WHERE aa.user_id = {$this->userId}
          )
SQL
        )->queryAll();
        $this->items = [Item::TYPE_DEMEANOR => [], Item::TYPE_PERMISSION => [], Item::TYPE_ROLE => []];
        foreach ($items as $item) {
            $item['inherited'] = [$item['inherited']];
            $itemKey = ($item['type'] == Item::TYPE_ROLE) ? $item['id'] : $item['name'];
            if (!isset($this->items[$item['type']][$itemKey])) {
                $this->items[$item['type']][$itemKey] = $item;
            } else if ($item['type'] !== Item::TYPE_ROLE) {
                $this->items[$item['type']][$itemKey]['inherited'] = array_merge($this->items[$item['type']][$itemKey]['inherited'], $item['inherited']);
                if ($item['direct']) {
                    $this->items[$item['type']][$itemKey]['direct'] = true;
                }
            }

        }
    }
}