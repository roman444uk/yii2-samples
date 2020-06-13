<?php

namespace app\modules\system\components;

class PostgresqlArrayFieldBehavior extends \kossmoss\PostgresqlArrayField\PostgresqlArrayFieldBehavior
{
    protected function _postgresqlArrayEncode($value)
    {
        if (empty($value) || !is_array($value)) {
            return null;
        }

        $result    = '{';
        $firstElem = true;

        foreach ($value as $elem) {
            // add comma before element if it is not the first one
            if (!$firstElem) {
                $result .= ',';
            }

            if ($elem instanceof \PHPExcel_RichText) {
                $elem = $elem->getPlainText();
            }
			
            if (is_array($elem)) {
                $result .= $this->_postgresqlArrayEncode($elem);
            } else if (is_string($elem)) {
                $elem = addslashes(str_replace('\\', ' ', $elem));
				
                if (strpos($elem, ',') !== false) {
                    $result .= '"' . $elem . '"';
                } else {
                    $result .= $elem;
                }
            } else if (is_numeric($elem)) {
                $result .= $elem;
            } else {
                // we can only save strings and numeric
                throw new \Exception('Array contains other than string or numeric values, can\'t save to PostgreSQL array field');
            }
			
            $firstElem = false;
        }
		
        $result .= '}';
        return $result;
    }

    protected function _postgresqlArrayDecode($data, $start = 0)
    {
        if (empty($data) || $data[0] != '{') {
            return null;
        }

        $result = [];

        $string = false;
        $quote  = '';
        $len    = strlen($data);
        $v      = '';

        for ($i = $start + 1; $i < $len; $i++) {
            $ch = $data[$i];

            if (!$string && $ch == '}') {
                if ($v !== '' || !empty($result)) {
                    $result[] = $v;
                }
                break;
            } else if (!$string && $ch == '{') {
                $v = $this->_postgresqlArrayDecode($data, $i);
            } else if (!$string && $ch == ',') {
                $result[] = $v;
                $v        = '';
            } else if (!$string && ($ch == '"' || $ch == "'")) {
                if ($data[$i - 1] == '\\') {
                    $v = substr($v, 0, -1) . $ch;
                } else {
                    $string = true;
                    $quote  = $ch;
                }
            } else if ($string && $ch == $quote && $data[$i - 1] == "\\") {
                $v = substr($v, 0, -1) . $ch;
            } else if ($string && $ch == $quote && $data[$i - 1] != "\\") {
                $string = false;
            } else {
                $v .= $ch;
            }
        }

        return $result;
    }
}