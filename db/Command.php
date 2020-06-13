<?php

namespace app\modules\system\db;

class Command extends \yii\db\Command
{
    /**
     * @param $table
     * @param $columns
     * @return $this
     */
    public function insertUpdate($table, $columns)
    {
        $params = [];
        $sql = $this->db->getQueryBuilder()->insert($table, $columns, $params);

        return $this->setSql($sql)->bindValues($params);
    }
}
