<?php

namespace backend\controllers;

use backend\components\BackendController;
use common\enums\UserRole;
use common\models\Deliveries;
use common\models\DeliveriesSearch;
use common\models\OrdersAnalyticsSearch;
use common\services\DeliveriesService;
use kartik\grid\EditableColumnAction;
use Yii;
use yii\data\ActiveDataProvider;
use yii\filters\AccessControl;
use yii\helpers\ArrayHelper;

class DeliveriesController extends BackendController
{
    /**
     * @var DeliveriesService
     */
    private $deliveriesService;

    /**
     * @param DeliveriesService $deliveriesService
     */
    public function __construct(DeliveriesService $deliveriesService)
    {
        $this->deliveriesService = $deliveriesService;
    }

    /**
     * {@inheritdoc}
     */
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    [
                        'allow' => true,
                        'roles' => [UserRole::ADMIN],
                    ],
                ],
            ],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function actions()
    {
        return ArrayHelper::merge(parent::actions(), [
            'editname' => [
                'class'           => EditableColumnAction::class,
                'modelClass'      => Deliveries::class,
                'outputValue'     => static function ($model, $attribute, $key, $index) {
                    return $model->$attribute;
                },
                'outputMessage'   => static function ($model, $attribute, $key, $index) {
                    return Yii::t('app', 'Saved');
                },
                'showModelErrors' => true,
                'errorOptions'    => ['header' => '']
            ]
        ]);
    }

    /**
     * @return mixed
     */
    public function actionIndex()
    {
        return $this->render('index', [
            'dataProvider' => (new DeliveriesSearch())->search(Yii::$app->request->queryParams)
        ]);
    }

    /**
     * @return void
     */
    public function actionAjaxCreate()
    {
        return $this->deliveriesService->create(Yii::$app->request->post);
    }

    /**
     * @param int $id
     *
     * @return mixed
     */
    public function actionDelete(int $id)
    {
        $this->deliveriesService->delete($id);

        return $this->redirect(Yii::$app->request->referrer ?? ['/eliveries']);
    }
}