<?php

namespace app\modules\system\components;

/**
 * Компонент позволяет писать даннные в листы существующего excel-файла используя его как шаблон и сохранять результат
 * в отдельную указанную папку
 *
 * $data = [
 *      'лист1'           => [  //лист1 - название листа в файле или его индекс
 *          cells = [
 *              'A1'  => 'Hello Word' //заполнение ячеек в шаблоне
 *          ],
 *          startWriteRowsIndex => 1, //строка начиная с которой будут вставляться данные из 'rows'
 *          rows = [
 *              [
 *                  'любой_ключ1'    => 1,
 *                  'любой_ключ2'    => '1.01.2017',
 *                  'любой_ключ3'    => 10,
 *                  'любой_ключ4'    => 100,
 *              ]
 *              [
 *                  'любой_ключ1'    =>  2,
 *                  'любой_ключ2'    => '1.02.2017',
 *                  'любой_ключ3'    => 20,
 *                  'любой_ключ4'    => 200,
 *              ]
 *          ],
 *          'mergeCells' => [
 *               'B6:B10',
 *               'C1:D5
 *          ]
 *      ],
 *      'лист2'           => [
 *          cells = [
 *              'B2'  => '24.02.17'
 *          ],
*           startWriteRowsIndex => 1,
 *          rows = [
 *               [
 *                  'number'          => 1,
 *                  'date'            => '1.01.2017',
 *                  'count'           => 10,
 *                  'total_amount'    => 100,
 *              ]
 *          ],
 *      ]
 * ]
 */

class XlsWriterComponent
{
    public $file;
	
    public $model;
	
    public $data;
	
    public $save_path;
	
    private $_excelFactory;

    public function __construct($fileName, $data = [], $savePath)
    {
        $this->file = $fileName;
        $this->data = $data;
        $this->save_path = $savePath;
    }

    public function run()
    {
        if (!file_exists($this->file)) {
            throw new \Exception('Input file not found');
        }

        if (!$this->save_path) {
            throw new \Exception('Save path is not specified');
        }

        $this->_excelFactory = \PHPExcel_IOFactory::load($this->file);
        $cacheMethod = \PHPExcel_CachedObjectStorageFactory::cache_to_phpTemp;
        $cacheSettings = array( 'memoryCacheSize ' => '256MB');
        \PHPExcel_Settings::setCacheStorageMethod($cacheMethod, $cacheSettings);

        foreach ($this->data as $sheetKey => $sheetData) {
            $sheet = null;
            if (is_string($sheetKey)) {
                $sheet = $this->_excelFactory->getSheetByName($sheetKey);
                $this->_excelFactory->setActiveSheetIndexByName($sheetKey);
            } elseif (is_integer($sheetKey)) {
                $sheet = $this->_excelFactory->getSheet($sheetKey);
                $this->_excelFactory->setActiveSheetIndex($sheetKey);
            }
			
            if (!$sheet) {
                continue;
            }
			
            $this->prepareSheetData($sheet, $sheetData);
        }
        $objWriter = \PHPExcel_IOFactory::createWriter($this->_excelFactory, 'Excel2007');

        if (!file_exists(dirname($this->save_path))) {
            mkdir(dirname($this->save_path), 0777, true);
        }

        return $objWriter->save($this->save_path);
    }

    private function prepareSheetData(\PHPExcel_Worksheet $sheet, $sheetData)
    {
        if ($sheetData && isset($sheetData['cells'])) {
            $this->prepareCellValues($sheet, $sheetData['cells']);
        }
		
        if ($sheetData && isset($sheetData['rows'])) {
            if (isset($sheetData['startWriteRowsIndex'])) {
                $startIndex = $sheetData['startWriteRowsIndex'];
            } else {
                $startIndex = 1;
            }
			
            $rowsOptions=isset($sheetData['rowsOptions'])?$sheetData['rowsOptions']:[];

            $this->prepareRows($sheet, $sheetData['rows'], $startIndex,$rowsOptions);
        }
		
        if ($sheetData && isset($sheetData['removeRows'])) {
            $this->removeRows($sheet, $sheetData['removeRows']);
        }

        if ($sheetData && isset($sheetData['mergeCells'])) {
            $this->mergeCells($sheet, $sheetData['mergeCells']);
        }
    }

    private function prepareCellValues(\PHPExcel_Worksheet $sheet, $dataCells)
    {
        foreach ($dataCells as $key => $value) {
            $sheet->setCellValue($key, $value);
        }
    }

    private function prepareRows(\PHPExcel_Worksheet $sheet, $dataRows, $startIndex = 0, $rowsOptions = [])
    {
        $row = $startIndex;

        $sheet->insertNewRowBefore($row, count($dataRows));
        $sheet->fromArray($dataRows, null, 'A'.$startIndex);

        foreach ($dataRows as $row_key=>$rows) {

            $col = 0;

            foreach ($rows as $key => $value) {
                $cell = $sheet->getCellByColumnAndRow($col, $row);

                if(isset($rowsOptions['autoSize']) && $rowsOptions['autoSize'] && !empty($value)){
                    $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
                }
				
                if(!empty($rowsOptions['rowColors'][$row_key])){
                    $cell->getStyle()->getFont()->setColor($rowsOptions['rowColors'][$row_key]);
                }

                if(isset($rowsOptions['bold']) && in_array($key,$rowsOptions['bold'])){
                    $cell->getStyle()->getFont()->setBold(true);
                }
				
                if(!empty($rowsOptions['fontSize'][$key])){
                    $cell->getStyle()->getFont()->setSize($rowsOptions['fontSize'][$key]);
                }
				
                $col++;
            }
            if (!empty($rowsOptions['mergeCells'][$row_key])) {
                foreach($rowsOptions['mergeCells'][$row_key] as $mergeCell){
                    $sheet->mergeCellsByColumnAndRow($mergeCell[0],$row,$mergeCell[1],$row);
                }

            }

            $row++;
        }
    }
	
    private function removeRows(\PHPExcel_Worksheet $sheet, $removeRows)
	{
        foreach ($removeRows as $removeRow) {
            $sheet->removeRow($removeRow,1);
        }
    }
    private function mergeCells(\PHPExcel_Worksheet $sheet, $mergeCellsData)
	{
        foreach ($mergeCellsData as $mergeCellsString) {
            $sheet->mergeCells($mergeCellsString);
        }
    }
}