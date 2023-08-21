<?php

namespace common\services;

use common\exceptions\AppException;
use Throwable;
use Yii;

class LogService
{
    /**
     * @var string|null
     */
    protected static ?string $_REQUEST_ID = null;

    /**
     * @return string
     */
    public function getRequestId(): string
    {
        if (static::$_REQUEST_ID) {
            return static::$_REQUEST_ID;
        }

        $headers = Yii::$app->request->headers;
        if ($headers->has('X-Request-Id')) {
            $this->setRequestId($headers->get('X-Request-Id'));
        } else {
            $this->generateRequestId();
        }

        return static::$_REQUEST_ID;
    }

    /**
     * @return void
     * @throws \yii\base\Exception
     */
    public function generateRequestId(): void
    {
        $this->setRequestId(Yii::$app->security->generateRandomString());
    }

    /**
     * @param string $value
     *
     * @return void
     */
    public function setRequestId(string $value): void
    {
        static::$_REQUEST_ID = $value;

        Yii::$app->log->setRequestId($value);
    }

    /**
     * @param string $message
     * @param array $context
     *
     * @return void
     */
    public function debug(string $message, array $context = []): void
    {
        Yii::debug([$message, $context]);
    }

    /**
     * @param string $message
     * @param array $context
     *
     * @return void
     */
    public function info(string $message, array $context = []): void
    {
        Yii::info([$message, $context]);
    }

    /**
     * @param string $message
     * @param array $context
     *
     * @return void
     */
    public function warning(string $message, array $context = []): void
    {
        Yii::warning([$message, $context]);
    }

    /**
     * @param string $message
     * @param array $context
     *
     * @return void
     */
    public function error(string $message, array $context = []): void
    {
        Yii::error([$message, $context]);
    }

    /**
     * @param Throwable $exception
     * @param array $context
     *
     * @return void
     */
    public function exception(Throwable $exception, array $context = []): void
    {
        if ($exception instanceof AppException) {
            $context = array_merge($exception->getAdditionals(), $context);
        }

        if (isset($context['trace'])) {
            $this->warning('Please don\'t use "trace" context key, this reserved for exceptions');
            $context['_other_trace'] = $context['trace'];
        }

        $context['trace'] = $exception->getTraceAsString();
        $context['file'] = $exception->getFile();
        $context['line'] = $exception->getLine();

        $message = $exception->getMessage();
        if ($message === '') {
            $message = get_class($exception);
        }
        $this->error($message, $context);
    }

    /**
     * @param array $values
     *
     * @return void
     */
    public function switchContext(array $values): void
    {
        $values['request_id'] = $this->getRequestId();

        Yii::$app->log->setContext($values);
    }
}
