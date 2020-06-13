<?php

namespace app\modules\system\components;


use app\modules\system\exceptions\FileExtensionException;

class XlsReaderComponent
{
    const EMPTY_VALUE = '';
	
    const DATE_FORMAT = 'd.m.Y';

    public static function readFile($fileName, $firstRowNumber = 1,  $extension = null, $exitIfEmptyRow = false, $csv_separator = ';')
    {
        if ($extension === null) {
            $extension = pathinfo($fileName, PATHINFO_EXTENSION);
        }

        switch (strtolower($extension)) {
            case 'xls':
            case 'xlsx':
                return self::readXlsFile($fileName, $firstRowNumber, $exitIfEmptyRow);
            case 'csv':
                return self::readCsvFile($fileName, $firstRowNumber, $exitIfEmptyRow, $csv_separator);
            default:
                throw new FileExtensionException();
        }
    }

    private static function readXlsFile($fileName, $firstRowNumber = 1, $exitIfEmptyRow = false)
    {
        $excel = \PHPExcel_IOFactory::load($fileName);
        $sheet = $excel->getActiveSheet();
        $data  = [];
        $i     = 1;
        foreach ($sheet->getRowIterator() as $i => $row) {
            if ($i < $firstRowNumber) {
                continue;
            }
			
            // получим итератор ячеек текущей строки
            $cellIterator = $row->getCellIterator();
            $rowData = [];
			
            // пройдемся циклом по ячейкам строки
            // этот массив будет содержать значения каждой отдельной строки
            $allColsAreEmpty = true;
            foreach ($cellIterator as $key=>$cell) {
                /** @var \PHPExcel_Cell $cell */
                //заносим значения ячеек одной строки в отдельный массив
                if (($value = $cell->getValue()) !== null) {
                    if (\PHPExcel_Shared_Date::isDateTime($cell) && ($date = \PHPExcel_Shared_Date::ExcelToPHP($value)) && is_int($date)) {
                        $value = date(self::DATE_FORMAT, $date);
                    }
					
                    if (trim($value) == '') {
                        $rowData[$key] = self::EMPTY_VALUE;
                        $allColsAreEmpty &= true;
                    } else {
                        $rowData[$key] = $value;
                        $allColsAreEmpty &= false;
                    }
                } else {
                    $rowData[$key] = self::EMPTY_VALUE;
                    $allColsAreEmpty &= true;
                }
            }

            //прерываем чтение на пустой строке если необходимо
            if ($allColsAreEmpty && $exitIfEmptyRow) {
                return $data;
            }

            //заносим массив со значениями ячеек отдельной строки в "общий массив строк"
            if (count($rowData) > 0) {
                $data[$i] = $rowData;
            }
        }

        return $data;
    }

    private static function readCsvFile($fileName, $firstRowNumber = 1, $exitIfEmptyRow = false, $csv_separator)
    {
        $lines = file($fileName);
        $data = [];
        foreach ($lines as $i => $line) {
            $rowData = str_getcsv($line, $csv_separator);
            $rowData = array_map(function ($value) {
                return trim($value) == ''? self::EMPTY_VALUE: $value;
            }, $rowData);

            //прерываем чтение на пустой строке если необходимо
            if (empty($rowData) && $exitIfEmptyRow) {
                return $data;
            }

            if ($firstRowNumber && ($i >= $firstRowNumber - 1)) {
                // increment $i to start from 1 like excel
                $data[++$i] = $rowData;
            } else {
                continue;
            }
        }
        return $data;
    }
}