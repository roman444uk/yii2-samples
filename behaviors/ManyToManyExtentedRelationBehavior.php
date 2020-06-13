<?php

namespace app\modules\system\behaviors\models\ar;

use yii\base\Exception;
use yii\db\ActiveRecord;
use yii\helpers\ArrayHelper;

/**
 * ManyToManyExtentedRelationBehavior поведение для реализации связи многие ко многим.
 * Поддерживает сортировку связанных моделей и дублирующиеся записи
 *
 */
class ManyToManyExtentedRelationBehavior extends ManyToManyRelationBehavior
{
    /**
     * @var string атрибут, по которому сортировать связанные модели
     */
    public $sortField;

    /**
     * Обработчик события после сохранения модели.
     */
    public function afterSave($event)
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

        /// Если список связанных сущностей не присваивался, значит их обновлять не нужно
        if (!$this->_isRelationValuesChanged) {
            return;
        }
		
        // Вытягиваем список доступных объектов, если передана функция
        if ($this->availableIdsCallback instanceof \Closure) {
            $availableIds = call_user_func($this->availableIdsCallback);
        }

        $existModels = $this->getExistRelationValues();

        // Кладем в массив значения полей для связываемых моделей
        $existRelationValues = ArrayHelper::getColumn($existModels, $this->relatedModelField);

        foreach ($this->_relationValues as $sort => $relationValue) {
            $models = array_filter($existModels, function ($model) use ($relationValue) {
                return $model->{$this->relatedModelField} == $relationValue;
            });
            $model = reset($models);

            if ($model && $model !== null) {
                $newSort = $sort + 1;
                if ($this->sortField && $model->{$this->sortField} != $newSort) {
                    $model->{$this->sortField} = $sort + 1;
                    $model->save();
                }
                unset($existModels[$model->id]);
            } else {
                /** @var $model ActiveRecord */
                $model = new $this->relationModel;
                $model->{$this->currentModelField} = $this->currentModelValue;
                $model->{$this->relatedModelField} = $relationValue;

                if ($this->sortField) {
                    $model->{$this->sortField} = $sort + 1;
                }
                $model->save();
            }
        }

        foreach ($existModels as $existModel) {
            /** @var $existModel ActiveRecord */
            $existModel->delete();
        }
    }

    /**
     * Возвращает список связанных сущностей из БД.
     * @return array
     */
    public function getExistRelationValues()
    {
        /* @var $model \yii\db\ActiveRecord */
        $model = $this->relationModel;
        if (!$this->_existRelationValues) {

            $query = $model::find()
                ->where("{$this->currentModelField} = :value", [':value' => $this->currentModelValue])
                ->indexBy('id');

            if ($this->sortField) {
                $query->orderBy($this->sortField);
            }
            $this->_existRelationValues = $query->all();
        }

        return $this->_existRelationValues;
    }

    /**
     * @inheritdoc
     */
    public function __get($name)
    {
        if ($name == $this->currentModelAttribute) {
            return array_values(ArrayHelper::getColumn($this->getExistRelationValues(), $this->relatedModelField));
        }
        return parent::__get($name);
    }
}