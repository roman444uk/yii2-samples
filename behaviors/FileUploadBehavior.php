<?php

namespace app\modules\system\behaviors\models\ar;

use Yii;
use yii\base\Behavior;
use yii\base\InvalidConfigException;
use yii\db\ActiveRecord;
use app\modules\system\models\File;
use yii\web\UploadedFile;
use yii\base\DynamicModel;
use \yii\helpers\FileHelper;

/**
 * Class FileUploadBehavior
 * use app\modules\system\behaviors\models\ar\FileUploadBehavior;
 *
 * function behaviors()
 * {
 *     return [
 *         [
 *             'class' => FileUploadBehavior::className(),
 *             'attribute' => 'files',
 *             'uploadFolder' => 'files',
 *             'tempFolder' => 'temp',
 *             'validator' => 'file,
 *             'validatorOptions' => []
 *             ]
 *         ],
 *     ];
 * }
 *
 *
 *
 * @package app\modules\system\behaviors\models\ar
 */
class FileUploadBehavior extends Behavior
{
    /**
     * @event события вызывается после успешной загрузки файла
     */
    const EVENT_AFTER_UPLOAD = 'afterUpload';

    /**
     * @var string папка для сохранения загруженных файлов
     */
    public $uploadFolder = 'files';

    /**
     * @var string временная папка для сохранения загруженных файлов
     * используется если модели для которой загружаются файлы ещё нет в БД
     */
    public $tempFolder = 'temp';

    /**
     * @var string атрибут, по которому нужно обращаться к модели, чтобы получить список загружаемых файлов
     */
    public $attribute = 'files';

    /**
     * @var string правило валидации
     */
    public $validator = 'file';

    /**
     * @var string опции валидации
     */
    public $validatorOptions = [
        //TODO 'maxSize'    => 1024 * 1024 * 10
    ];

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent:b:init();

        if ($this->attribute === null) {
            throw new InvalidConfigException('The "attribute" property must be set.');
        }

    }

    /**
     * @inheritdoc
     */
    public function events()
    {
        return [
            ActiveRecord::EVENT_AFTER_INSERT => 'handleAfterInsert',
        ];
    }

    /**
     * Загрузка файла.
     * Вызывается из контроллера
     * @return array
     */
    public function uploadFile()
    {
        $file = UploadedFile::getInstance($this->owner, $this->attribute);
        if ($file == null) {
            $file = UploadedFile::getInstanceByName($this->attribute);
        }

        // Валидация
        $model = new DynamicModel(compact('file'));
        $model->addRule('file', $this->validator, $this->validatorOptions)->validate();

        if ($model->hasErrors()) {
            $result = [
                'errors' => $model->getFirstError('file')
            ];
        } else {

            // Если модель новая то загружаем во временную папку
            $uploadPath = $this->owner->isNewRecord ? $this->getTempUploadPath() : $this->getUploadPath();

            if (!is_dir($uploadPath)) {
                FileHelper::createDirectory($uploadPath);
            }
            // Сохраняем оригинальное название файла
            $origin_name = $model->file->name;
            $model->file->name = $this->getRandomFileName($model->file);

            if ($model->file->saveAs($uploadPath . '/' . $model->file->name)) {

                // Относительная ссылка на файл
                $relativeUploadPath = $this->owner->isNewRecord ? $this->getTempUploadRelativePath() : $this->getUploadRelativePath();
                $uploadUrl = $relativeUploadPath . $model->file->name;

                // Возвращаем на клиент параметры загруженнго файла
                $resultFile = [
                    'name'        => $model->file->name,
                    'type'        => $model->file->type,
                    'size'        => $model->file->size,
                    'origin_name' => $origin_name,
                    'url'         => $uploadUrl,
                ];

                // Прикрепляем файл к существующей модели
                if (!$this->owner->isNewRecord) {
                    $fileModel = $this->saveFileModel($uploadUrl, $origin_name);
                    if (!$fileModel) {
                        return [
                            'errors' => 'Не удалось загрузить файл'
                        ];
                    }

                    $resultFile['id'] = $fileModel->id;
                } else {
                    // Если модель новая, отдаём название файла
                    $resultFile['id'] = $model->file->name;
                }

                $this->owner->trigger(self::EVENT_AFTER_UPLOAD);

                $result['files'][] = $resultFile;
            } else {
                $result = [
                    'errors' => 'Не удалось загрузить файл'
                ];
            }
        }

        return $result;
    }

    /**
     * Удаление файла.
     * Вызывается из контроллера
     * @return array
     */
    public function deleteFile($file_id)
    {
        $owner = $this->owner;
        $ownerModel = $owner->getModelId();

        $success = true;
        $file_id = intval($file_id);

        if ($file_id > 0) {
            $fileModel = File::find()->where([
                'id'             => intval($file_id),
                'owner_model_id' => $ownerModel->id,
                'owner_id'       => $owner->id,
            ])->andWhere(['is', 'deleted_at', null])->one();

            if ($fileModel) {
                // Помечаем как удаленный
                $fileModel->deleted_at = date('Y-m-d H:i:s');
                $success = $fileModel->save();
            }
        }

        $result = [
            'success' => $success
        ];

        return $result;
    }

    /**
     * Возвращает модели загруженных файлов.
     * @return array
     */
    public function getFileModels()
    {
        $owner = $this->owner;
        $ownerModel = $owner->getModelId();

        return File::find()->where([
            'owner_model_id' => intval($ownerModel->id),
            'owner_id'       => intval($owner->id),
        ])->andWhere(['is', 'deleted_at', null])->all();
    }

    /**
     * Возвращает модель файла по Id для скачивания
     * @return array
     */
    public function getFileModelById($fileId)
    {
        $owner = $this->owner;
        $ownerModel = $owner->getModelId();

        return File::find()->where([
            'id'             => $fileId,
            'owner_model_id' => intval($ownerModel->id),
        ])->andWhere(['is', 'deleted_at', null])->one();
    }

    /**
     * Метод вызывается только при создании новой записи
     * При загрузке файлов в новую модель на клиент возвращаются ссылка на файл и его оригинальное название
     * Данные  должны приходить в виде [['name' => name1, 'origin_name' => origin_name1], ['name' => name2, 'origin_name' => origin_name2]]
     * или в виде ['name1', 'name2']
     * Которые при сохранении обрабатываются в этом методе
     */
    public function handleAfterInsert()
    {
        /** @var ActiveRecord $model */
        $owner = $this->owner;
        $files = $owner->{$this->attribute};

        if (empty($files)) {
            return;
        }
        if (!is_dir($this->getUploadPath())) {
            FileHelper::createDirectory($this->getUploadPath());
        }

        foreach ($files as $tempFileName) {
            if (is_string($tempFileName)) {
                $tempFile = $this->getTempUploadPath() . '/' . $tempFileName;
                $newFile = $this->getUploadPath() . '/' . $tempFileName;
                $file_name = $tempFileName;
                $origin_name = $tempFileName;

            } elseif (is_array($tempFileName)) {
                if (!isset($tempFileName['name'])) {
                    throw new InvalidConfigException('The "name" property must be set.');
                } else {
                    $tempFile = $this->getTempUploadPath() . '/' . $tempFileName['name'];
                    $newFile = $this->getUploadPath() . '/' . $tempFileName['name'];
                    $file_name = $tempFileName['name'];
                }
                if (!isset($tempFileName['origin_name'])) {
                    $origin_name = $file_name;
                } else {
                    $origin_name = $tempFileName['origin_name'];
                }
            } else {
                continue;
            }

            // Если временный файл существует то переносим файл в папку модели
            // Сохраняем файл в бд
            if (file_exists($tempFile) && rename($tempFile, $newFile)) {
                $this->saveFileModel($this->getUploadRelativePath() . $file_name, $origin_name);
            } else {
                $this->owner->addError($this->attribute, 'Не удалось сохранить загруженный файл');
            }
        }
    }

    /**
     * Сохранение файла в бд
     * @param string $uploadUrl относительная ссылка на файл
     * @param string $origin_name оригинальное название файла
     * @return File|bool
     * @throws \Exception
     */
    protected function saveFileModel($uploadUrl, $origin_name, $ownerAttribute = null)
    {
        $owner = $this->owner;
        $ownerModel = $owner->getModelId();

        if ($ownerModel == null || !$ownerModel->id) {
            throw new \Exception('Не удалось получить ID модели для класса: ' . get_class($owner));
        }

        $fileModel = new File([
            'owner_id'         => $owner->id,
            'owner_model_id'   => $ownerModel->id,
            'owner_attribute'  => $this->attribute,
            'file_path'        => $uploadUrl,
            'origin_file_name' => $origin_name,
        ]);

        return $fileModel->save() ? $fileModel : false;
    }

    public function getRandomFileName($file)
    {
        return Yii::$app->security->generateRandomString(20) . '.' . $file->extension;
    }

    public function getUploadPath()
    {
        return FileHelper::normalizePath(\Yii::getAlias('@webroot/' . $this->getUploadRelativePath()));
    }

    public function getUploadRelativePath()
    {
        $intDir = $this->getIntermediateDirectory();

        return '/' . $this->uploadFolder . '/' . strtolower((new \ReflectionClass($this->owner))->getShortName()) . '/' . $intDir . '/';
    }

    public function getTempUploadPath()
    {
        return FileHelper::normalizePath(\Yii::getAlias('@webroot/' . $this->getTempUploadRelativePath()));
    }

    public function getTempUploadRelativePath()
    {
        return '/' . $this->tempFolder . '/';
    }

    /**
     * //TODO  Название папки должно генерироваться динимачески при достижении определенного кол-ва файлов
     * Возвращает название каталога в который будут сохраняться файлы внутри папки текущей модели
     * @return string
     */
    protected function getIntermediateDirectory()
    {
        return 'A';
    }

    /**
     * @return $this
     * Метод для доступа к методам текущего класса и
     * для переопределения геттера
     */
    public function getUpload()
    {
        return $this;
    }
}