<?php

namespace app\modules\system\behaviors\models\ar;

use app\modules\system\db\ActiveRecord;
use app\modules\system\helpers\DateHelper;
use Yii;
use yii\base\Behavior;
use yii\base\ModelEvent;
use yii\validators\Validator;

class TimezoneBehavior extends Behavior
{
    /**
     * @var array
     * Массив атрибутов типа timestamp
     */
    public $datetimeAttributes = [];

    /**
     * @var array
     * Массив атрибутов типа date
     */
    public $dateAttributes = [];

    /**
     * @var array
     * Массив атрибутов типа timestamp
     * с локальной временной зоной
     */
    public $localDatetimeAttributes = [];

    /**
     * @inheritdoc
     */
    public function events()
    {
        return [
            ActiveRecord::EVENT_BEFORE_VALIDATE => 'handleBeforeValidate',
            ActiveRecord::EVENT_BEFORE_INSERT   => 'handleBeforeSave',
            ActiveRecord::EVENT_BEFORE_UPDATE   => 'handleBeforeSave',
        ];
    }

    /**
     * @param \yii\db\ActiveRecord $owner
     * Добавляем safe валидатор для дат и времени
     */
    public function attach($owner)
    {
        $attributes = \yii\helpers\ArrayHelper::merge($this->datetimeAttributes, $this->dateAttributes, $this->localDatetimeAttributes);
        if ($attributes) {
            $validator = Validator::createValidator('yii\validators\SafeValidator', $owner, $attributes);
            $owner->validators->append($validator);
        }
        parent::attach($owner);
    }

    /**
     * @param ModelEvent $event
     * Добавляем date валидатор для дат
     */
    public function handleBeforeValidate($event)
    {
        /**
         * @var $owner \yii\db\ActiveRecord
         */
        $owner = $event->sender;
        $this->attachDateValidator($owner, $this->datetimeAttributes, \Yii::$app->formatter->datetimeFormat);
        $this->attachDateValidator($owner, $this->dateAttributes, \Yii::$app->formatter->dateFormat);
    }

    /**
     * @param ModelEvent $event
     * Преобразуем данные для вставки в БД
     */
    public function handleBeforeSave($event)
    {
        /**
         * @var $owner \yii\db\ActiveRecord
         */
        $owner = $event->sender;
        foreach ($this->localDatetimeAttributes as $attribute) {
            if (is_string($owner->{$attribute})) {
                if (!empty($owner->{$attribute})) {
                    $owner->{$attribute} = DateHelper::toUts($owner->{$attribute}, $this->owner->timezone->key);
                }
            }

        }
        $this->formatToDb($owner, $this->datetimeAttributes, 'Y-m-d H:i:sP');
        $this->formatToDb($owner, $this->dateAttributes, 'Y-m-d');
    }

    /**
     * @param $owner \yii\db\ActiveRecord
     * @param $attributes []
     * @param $format string
     */
    protected function attachDateValidator($owner, $attributes, $format)
    {
        if (!empty($attributes)) {
            foreach ($attributes as $key => $attribute) {
                if (
                    // если значение является объектом класса \yii\db\Expression отключаем валидатор
                    (!is_string($owner->{$attribute}) && ($owner->{$attribute} instanceof \yii\db\Expression))
                    ||
                    // если значение не менялось отключаем валидатор
                    !$owner->isAttributeChanged($attribute)
                ) {
                    unset($attributes[$key]);
                }
            }
            if (!empty($attributes)) {
                $validator = Validator::createValidator('yii\validators\DateValidator', $owner, $attributes,
                    ['format' => $format]);
                $owner->validators->append($validator);
            }
        }
    }

    /**
     * @param $owner \yii\db\ActiveRecord
     * @param $attributes []
     * @param $format string
     */
    protected function formatToDb($owner, $attributes, $format)
    {
        foreach ($attributes as $attribute) {
            if (is_string($owner->{$attribute})) {
                if (!empty($owner->{$attribute}))
                    $owner->{$attribute} = date($format, strtotime($owner->{$attribute}));
                else
                    $owner->{$attribute} = null;
            }
        }
    }

    /**
     * @param string $name
     * @return mixed|string
     * Пробуем форматировать дату и время,
     * в случае неккоректного формата вылетит Exception,
     * в этом случае возвращаем оригинальное значение
     */
    public function __get($name)
    {
        try {
            if (in_array($name, $this->datetimeAttributes)) {
                return \Yii::$app->formatter->asDatetime($this->owner->{$name});
            }
            if (in_array($name, $this->dateAttributes)) {
                return \Yii::$app->formatter->asDate($this->owner->{$name});
            }
            if (in_array($name, $this->localDatetimeAttributes)) {
                return DateHelper::toTimezone($this->owner->{$name}, $this->owner->timezone->key);
            }
        } catch (\Exception $e) {
            return $this->owner->__get($name);
        }

        if (isset($this->owner->$name))
            return $this->owner->$name;

        return parent::__get($name);
    }
}
