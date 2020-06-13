<?php

namespace app\modules\system\behaviors\models\ar;

use yii\base\Behavior;
use app\modules\system\db\ActiveRecord;
use yii\base\DynamicModel;
use yii\base\Exception;
use yii\helpers\Inflector;

/**
 * Class ManyToManyRelationBehavior поведение для сохранения связи моделей "многие ко многим"
 * @package app\modules\system\behaviors\models\ar
 */
class ManyToManyRelationBehavior extends Behavior
{
    /**
     * @var string модель отношения
     */
    public $relationModel;

    /**
     * @var string внешний ключ текущей модели
     */
    public $currentModelField;

    /**
     * @var string значение внешнего ключа текущей модели
     */
    public $currentModelValue;

    /**
     * @var string внешний ключ второстепенной модели
     */
    public $relatedModelField;

    /**
     * @var array валидаторы, например [required]
     */
    public $validators = [];

    /**
     * @var string атрибут, по которому нужно обращаться к модели, чтобы присвоить список связанных сущностей
     */
    public $currentModelAttribute;

    /**
     * @var array массив идентификаторов сущностей, которые доступны текущему пользователю
     */
    public $availableIdsCallback;

    /**
     * @var array загруженные из БД связанные сущности
     */
    protected $_existRelationValues = [];

    /**
     * @var bool свойство, хранящее признак того, был ли присвоен список связанных сущностей поведению
     */
    protected $_isRelationValuesChanged = false;

    /**
     * @var array список связанных сущностей
     */
    protected $_relationValues = [];

    /**
     * @inheritdoc
     */
    public function events()
    {
        return [
            ActiveRecord::EVENT_BEFORE_VALIDATE => 'beforeValidate',
            ActiveRecord::EVENT_AFTER_UPDATE    => 'afterSave',
            ActiveRecord::EVENT_AFTER_INSERT    => 'afterSave',
        ];
    }

    /**
     * @return bool
     */
    public function getIsIsRelationValuesChanged()
    {
        return $this->_isRelationValuesChanged;
    }

    /**
     * Обработчик события до валидации.
     */
    public function beforeValidate()
    {
        if (!empty($this->validators)) {
            $model = new DynamicModel([
                $this->currentModelAttribute => $this->_relationValues,
            ]);

            foreach ($this->validators as $validatorName) {
                $model->addRule($this->currentModelAttribute, $validatorName, [])->validate();
            }

            if ($model->hasErrors()) {
                $errors = $model->errors[$this->currentModelAttribute];
                foreach ($errors as &$error) {
                    $inflectedName = Inflector::camel2words($this->currentModelAttribute, true);
                    $error = str_replace($inflectedName, $this->owner->getAttributeLabel($this->currentModelAttribute), $error);
                }
                $this->owner->addErrors([
                    $this->currentModelAttribute => $errors
                ]);
            }
        }
    }

    /**
     * Обработчик события после сохранения модели.
     */
    public function afterSave()
    {
        if ($this->currentModelValue === null) {
            if (property_exists($this->owner, 'id') || $this->owner->hasAttribute('id')) {
                $this->currentModelValue = $this->owner->id;
            } else {
                throw new Exception('Значение текущей модели не указано и ID владельца поведения определить не удается.');
            }
        } else if ($this->currentModelAttribute === null) {
            throw new Exception('Не указан атрибут текущей модели, через который нужно обращаться к поведению.');
        }
		
        // Если список связанных сущностей не присваивался, значит их обновлять не нужно
        if (!$this->_isRelationValuesChanged) {
            return;
        }
		
        // Вытягиваем список доступных объектов, если передана функция
        if ($this->availableIdsCallback instanceof \Closure) {
            $availableIds = call_user_func($this->availableIdsCallback);
        }

        $existModels = $this->getExistRelationValues();
		
        // Кладем в массив значения полей для связываемых моделей
        $existRelationValues = array_keys($existModels);
        $deleteList = array_diff($existRelationValues, $this->_relationValues);
        $addList = array_diff($this->_relationValues, $existRelationValues);

        // Удаляем модели
        foreach ($deleteList as $key) {
            if (in_array($key, $availableIds)) {
                $existModels[$key]->delete();
            }
        }

        // Добавляем
        foreach ($addList as $relatedValue) {
            if (in_array($relatedValue, $availableIds)) {
                /** @var ActiveRecord $model */
                $model = new $this->relationModel;
                $model->{$this->currentModelField} = $this->currentModelValue;
                $model->{$this->relatedModelField} = $relatedValue;
                $model->save();
            }
        }
    }

    /**
     * Возвращает список связанных сущностей из БД.
     * @return array
     */
    public function getExistRelationValues()
    {
        $model = $this->relationModel;
        if (!$this->_existRelationValues) {
            $this->_existRelationValues = $model::find()
                ->where("{$this->currentModelField} = :value", [':value' => $this->currentModelValue])
                ->indexBy($this->relatedModelField)
                ->all();
        }
		
        return $this->_existRelationValues;
    }

    /**
     * @inheritdoc
     */
    public function __set($name, $value)
    {
        $value = is_array($value) ? $value : ($value ? [$value] : []);
        if ($name == $this->currentModelAttribute) {
            // сравниваем со значениями из БД, иначе _isRelationValuesChanged всегда возвращает true
           $isDiff = !empty(array_diff($value, array_keys($this->getExistRelationValues()))) || !empty(array_diff(array_keys($this->getExistRelationValues()), $value));

            if ($isDiff) {
                $this->_relationValues = $value;
                $this->_isRelationValuesChanged = true;
            }
        } else {
            parent::__set($name, $value);
        }
    }

    /**
     * @inheritdoc
     */
    public function __get($name)
    {
        if ($name == $this->currentModelAttribute) {
            //Если значение изменили через set
            if ($this->_isRelationValuesChanged) {
                return $this->_relationValues;
            }
			
            return array_keys($this->getExistRelationValues());
        }
		
        return parent::__get($name);
    }

    /**
     * @inheritdoc
     */
    public function canSetProperty($name, $checkVars = true)
    {
        return $name == $this->currentModelAttribute ? true : parent::canSetProperty($name, $checkVars);
    }

    /**
     * @inheritdoc
     */
    public function canGetProperty($name, $checkVars = true)
    {
        return $name == $this->currentModelAttribute ? true : parent::canGetProperty($name, $checkVars);
    }
}