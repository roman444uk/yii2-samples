<?php

namespace common\components;

use common\enums\UserRole;
use common\helpers\S3ExchangeData;
use common\models\Orders;
use common\models\User;
use Telegram\Bot\Api as TelegramApi;
use Telegram\Bot\Exceptions\TelegramSDKException;
use Telegram\Bot\FileUpload\InputFile;
use Telegram\Bot\Objects\Message;
use yii\base\Component;
use yii\db\Expression;

abstract class TkBaseService extends Component
{
    /**
     * @var TkBaseApi
     */
    protected $api;

    /**
     * @var TelegramApi
     */
    protected TelegramApi $telegramApi;

    /**
     * @var string
     */
    protected string $tkKey;

    /**
     * @var string
     */
    protected string $tkBucket;

    /**
     * @var string
     */
    protected string $documentsDir;

    /**
     * @var string
     */
    protected string $usersDir;

    /**
     * @return bool
     */
    public bool $sendWaybillsToTG = true;

    /**
     * @param TelegramApi $telegramApi
     */
    public function __construct(TelegramApi $telegramApi)
    {
        $this->telegramApi = $telegramApi;
    }

    /**
     * Инициализация ТК
     *
     * @param string $tkKey
     * @param string $tkBucket
     *
     * @return void
     */
    protected function initTk(string $tkKey, string $tkBucket): void
    {
        $this->tkKey    = $tkKey;
        $this->tkBucket = $tkBucket;

        $this->documentsDir = \Yii::getAlias('@runtime').'/shipmentWaybills/'.$tkBucket.'/';
        if ( ! is_dir($this->documentsDir)) {
            mkdir($this->documentsDir, 0755, true);
        }

        $this->usersDir = \Yii::getAlias('@runtime').'/shipmentUsersNotFound/'.$tkBucket.'/';
        if ( ! is_dir($this->usersDir)) {
            mkdir($this->usersDir, 0755, true);
        }
    }

    /**
     * Загрузка накладных из ТК
     *
     * @return void
     */
    abstract function loadShipmentWaybills(): void;

    /**
     * Найти пользователя по списку номеров или создать
     *
     * @param array $phones
     * @param string $fullName
     *
     * @return User|null
     * @throws \yii\base\Exception
     */
    protected function findOrCreateUserByPhones(array $phones, string $fullName): ?User
    {
        $user = User::findUserByPhones($phones);
        if ( ! $user && count($phones)) {
            $user = User::createUser(
                '', $phones[0], $fullName, UserRole::USER, $phones[0]
            );
        }

        return $user;
    }

    /**
     * Обновление данных пользователя
     *
     * @param User $user
     * @param string $fullName
     * @param string $regPlace
     *
     * @return void
     */
    protected function updateUserData(User $user, string $fullName, string $regPlace): void
    {
        /** Есть ли у пользователя заказы из ТК */
        $ordersFromTk = Orders::find()
            ->where(['user_id' => $user->id])
            ->andWhere(new Expression('tk IS NOT NULL AND tk != ""'))
            ->count();

        if ($fullName && mb_strpos($user->fullname, 'Заказ с сайта') !== false) {
            $user->fullname = $fullName;
        }

        if ($regPlace && ! $ordersFromTk) {
            $user->regplace = $regPlace;
        }

        if ($user->dirtyAttributes) {
            $user->save();
        }
    }


    /**
     * Поиск заказа по накладной
     *
     * @param User $user
     * @param string $waybill
     * @param string $waybillUrl
     *
     * @return Orders
     */
    /**
     * @param string $waybill
     *
     * @return Orders|null
     */
    protected function findOrderByWaybill(string $waybill): ?Orders
    {
        return Orders::find()->where([
            'tk'      => $this->tkKey,
            'waybill' => $waybill,
        ])->one();
    }

    /**
     * Создание заказа по накладной
     *
     * @param User $user
     * @param string $waybill
     * @param string $waybillUrl
     *
     * @return Orders
     */
    protected function createOrder(User $user, string $waybill, string $waybillUrl): Orders
    {
        $order                = new Orders();
        $order->amount        = 0;
        $order->user_id       = $user->id;
        $order->delivery_type = 1;
        $order->status        = Orders::SUCCESS;
        $order->storage_id    = 1;
        $order->tk            = $this->tkKey;
        $order->waybill       = $waybill;
        $order->waybill_url   = $waybillUrl;

        $order->save();

        return $order;
    }

    /**
     * Загрузка документа в облако
     *
     * @param string $documentName
     * @param string $document
     * @param string $tkBucket
     *
     * @return string
     */
    protected function uploadDocumentToCloud(string $documentName, string $document, string $tkBucket): string
    {
        return S3ExchangeData::put($documentName, $document, 1, $tkBucket);
    }

    /**
     * Отправка накладной в телеграм
     *
     * @param string $caption
     * @param string $documentSource
     * @param string $documentName
     *
     * @return Message
     * @throws TelegramSDKException
     */
    protected function sendWaybillToTelegram(string $caption, string $documentSource, string $documentName): Message
    {
        $documentFile = InputFile::create($documentSource, $documentName);

        /** Отправка в телеграмм */
        return $this->telegramApi->sendDocument([
            'chat_id'  => \Yii::$app->params['tgbotTk']['chat_id'],
            'caption'  => $caption,
            'document' => $documentFile,
        ]);
    }

    /**
     * Отправка сообщения в телеграмм
     *
     * @param string $text
     *
     * @return Message
     * @throws TelegramSDKException
     */
    protected function sendMessageToTelegram(string $text): Message
    {
        /** Отправка в телеграмм */
        return $this->telegramApi->sendMessage([
            'chat_id' => \Yii::$app->params['tgbotTk']['chat_id'],
            'text'    => $text,
        ]);
    }
}