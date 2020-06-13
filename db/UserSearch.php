<?php

namespace app\modules\users\models\search;

use app\modules\companies\models\ar\CostCenter;
use app\modules\rbac\components\Item;
use app\modules\rbac\models\Assignment;
use app\modules\system\models\Draft;
use app\modules\system\traits\GridSearchModelTrait;
use app\modules\system\widgets\TagWidget;
use app\modules\users\models\aq\UserSearchActiveQuery;
use app\modules\users\models\ar\Profile;
use app\modules\users\models\form\User as UserForm;
use app\modules\users\UsersModule;
use app\modules\waybills\models\ar\Waybill;
use Yii;
use yii\data\ActiveDataProvider;
use app\modules\users\models\ar\User;
use yii\data\ArrayDataProvider;
use yii\helpers\ArrayHelper;
use app\modules\rbac\models\AuthItem;

/**
 * UserSearch класс для поиска модели app\modules\users\models\ar\User.
 */
class UserSearch extends User
{
    use GridSearchModelTrait;

    // TODO ошибка при инициализации поиска что нет свойства $containerIds
    public $containerIds = [];
	
    public $isDraft = false;

    /**
     * @inheritdoc
     */
    public function behaviors()
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            ['id', 'each', 'rule' => ['integer']],
            [
                ['costCenter', 'active', 'username', 'profile.location', 'profile.fullname', 'profile.post', 'password', 'email', 'activkey', 'create_at', 'lastvisit_at',
                    'archive', 'language', 'ip', 'roles', 'demeanors', 'createatDateFrom', 'createatDateTo', 'lastvisitDateFrom',
                    'lastvisitDateTo', 'roles', 'wwid', 'emails', 'locations', 'posts', 'post', 'vehicles', 'ids', 'manager', 'searchDrivers', 'costCenterIds', 'vehicles', 'isDraft',
                    'department', 'subdivision', 'working_startDateFrom', 'working_startDateTo','bands', 'statuses'
                ], 'safe'
            ]
        ];
    }

    /**
     * @inheritdoc
     */
    public function attributes()
    {
        return array_merge(parent::attributes(), [
            'id', 'costCenter', 'active', 'profile.fullname', 'createatDateFrom', 'createatDateTo', 'lastvisitDateFrom', 'lastvisitDateTo', 'roles',
            'wwid', 'emails', 'locations', 'posts', 'profile.post', 'profile.location', 'vehicles', 'manager', 'searchDrivers', 'statuses', 'costCenterIds', 'ids',
            'department', 'subdivision', 'working_startDateFrom', 'working_startDateTo','bands'
        ]);
    }

    /**
     * @return array
     */
    public function attributeLabels()
    {
        return array_merge(parent::attributeLabels(), [
            'emails' => UsersModule::t('Email'),
            'locations' => UsersModule::t('Регион/Город'),
            'posts' => UsersModule::t('Должность'),
            'costCenter' => UsersModule::t('Кост-центр'),
        ]);
    }

    /**
     * @inheritdoc
     */
    public function beforeValidate()
    {
        return parent::beforeValidate();
    }

    /**
     * @return array
     */
    public function searchRelations()
    {
        return [
            'passport', 'department', 'subdivision', 'profile', 'vehicles', 'leaders'
        ];
    }

    /**
     * Creates data provider instance with search query applied
     * @param array $params
     * @return ActiveDataProvider
     */
    public function search($params)
    {
        $this->load($params);
		
        if ($this->isDraft) {
            return $this->searchDraft($params);
        }
		
        $query = self::find()
			->joinWith(['profile', 'vehicles'])
			->addDriverAccess();
			
        $this->addQueryRelations($query);
		
        return $this->_search($query, $params);
    }

    protected function _search($query, $params)
    {
        /** @var UserSearchActiveQuery $query */
        $dataProvider = new ActiveDataProvider([
            'query' => $query,
            'pagination' => [
                'pageSize' => 30,
                'pageSizeLimit' => [1, 200]
            ],
            'sort' => [
                'attributes' => [
                    'asc' => ["{$query->tableName}.id"]
                ]
            ]
        ]);

        if ($this->searchDrivers) {
            $query->drivers();
        }

        // Exploding params values than have been come like a string
        foreach (['vehicles', 'statuses', 'roles', 'ids', 'locations', 'posts'] as $attr) {
            if (isset($params[$attr]) && strpos($params[$attr], ',')) {
                $params[$attr] = explode(',', $params[$attr]);
            }
        }

        if (($this->load($params) || $this->load($params, '')) && $this->validate()) {
            // grid filtering conditions
            $query->andFilterWhere([
                'create_at' => $this->create_at,
                'lastvisit_at' => $this->lastvisit_at,
                'core.profiles.wwid' => $this->wwid,
            ]);

            if ($this->ids) {
                $query->andFilterWhere(['in', 'system.users.id', explode(',', $this->ids)]);
            }

            if ($this->id) {
                if (is_array($this->id)) {
                    $query->andFilterWhere(['in', self::tableName() . '.id', $this->id]);
                } else {
                    $query->andFilterWhere([
                        self::tableName() . '.id' => $this->id
                    ]);
                }
            }

            $query->andFilterWhere(['ilike', 'email', $this->email])
                ->andFilterWhere(['ilike', 'activkey', $this->activkey])
                ->andFilterWhere(['ilike', 'language', $this->language])
                ->andFilterWhere(['ilike', 'wwid', $this->wwid])
                ->andFilterWhere(['ilike', 'ip', $this->ip])
                ->andFilterWhere(['ilike', 'core.profiles.post', $this->{'profile.post'}])
                ->andFilterWhere(['ilike', 'core.profiles.location', $this->{'profile.location'}])
                ->andFilterWhere(['>=', 'create_at', $this->dateDbFormat($this->createatDateFrom)])
                ->andFilterWhere(['<=', 'create_at', $this->dateDbFormat($this->createatDateTo)])
                ->andFilterWhere(['>=', 'lastvisit_at', $this->dateDbFormat($this->lastvisitDateFrom)])
                ->andFilterWhere(['<=', 'lastvisit_at', $this->dateDbFormat($this->lastvisitDateTo)])
                ->andFilterWhere(['>=', 'profiles.working_start', $this->dateDbFormat($this->working_startDateFrom)])
                ->andFilterWhere(['<=', 'profiles.working_start', $this->dateDbFormat($this->working_startDateTo)]);

            if ($this->{'profile.fullname'}) {
                $searchStrings = explode(' ', $this->{'profile.fullname'});

                foreach ($searchStrings as $searchString) {
                    $query->andFilterWhere(['OR',
                        ['ilike', 'core.profiles.name', $searchString],
                        ['ilike', 'core.profiles.surname', $searchString],
                        ['ilike', 'core.profiles.patronymic', $searchString],
                    ]);
                }
            }

            if ($this->locations) {
                $filterWhere = ['OR'];
                foreach ($this->locations as $location) {
                    $filterWhere[] = ['ilike', 'core.profiles.location', $location];
                }
				
                $query->andFilterWhere($filterWhere);
            }

            if ($this->posts) {
                $filterWhere = ['OR'];
                foreach ($this->posts as $post) {
                    $filterWhere[] = ['ilike', 'core.profiles.post', $post];
                }
				
                $query->andFilterWhere($filterWhere);
            }

            if ($this->bands) {
                $filterWhere = ['OR'];
                foreach ($this->bands as $band) {
                    $filterWhere[] = ['ilike', 'core.profiles.band', $band];
                }
                $query->andFilterWhere($filterWhere);
            }

            if ($this->emails) {
                $filterWhere = ['OR'];
                foreach ($this->emails as $email) {
                    $filterWhere[] = ['=', 'system.users.email', $email];
                }
                $query->andFilterWhere($filterWhere);
            }

            if ($this->statuses) {
                $query->byTagValues(is_array($this->statuses) ? $this->statuses : [$this->statuses]);
            }
			
            if ($this->costCenter) {
                $query->joinWith('costCenters');
                $query->andFilterWhere(['ilike', CostCenter::tableName() . '.name', $this->costCenter]);
            }

            if ($this->costCenterIds) {
                $query->byCostCenter($this->costCenterIds, $this->modelId->id);
            }

            // it's may be several tags used in one place, so we must call method for each separate group of values
            if (array_key_exists(TagWidget::INPUT_NAME_PREFIX, $params)) {
                foreach ($params[TagWidget::INPUT_NAME_PREFIX] as $paramValues) {
                    $query->byTagValues($paramValues);
                }
            }

            if ($this->roles) {
                $query->innerJoin(Assignment::tableName() . ' aa', "aa.user_id = {$query->tableName}.id")
                    ->andFilterWhere(['in', 'aa.item_id', $this->roles]);
            }
			
            if ($this->vehicles) {
                $query->andFilterWhere(['in', 'core.vehicles_users.vehicle_id', $this->vehicles]);
            }

            if ($this->department) {
                $query->joinWith(['department']);
                $query->andFilterWhere(['in', 'department.id', $this->department]);
            }

            if ($this->subdivision) {
                $query->joinWith(['subdivision']);
                $query->andFilterWhere(['in', 'subdivision.id', $this->subdivision]);
            }

            // TODO реализовать поиск по линейному руководителю
            if ($this->manager) {
                $query->innerJoin(['manager_profile' => Profile::className()])
                    ->andFilterWhere(['or',
                        ['ilike', 'manager_profile.name', $this->manager],
                        ['ilike', 'manager_profile.surname', $this->manager],
                        ['ilike', 'manager_profile.patronymic', $this->manager],
                    ]);
            }
        }

        $query->andFilterWhere([self::tableName() . '.active' => $this->active]);

        $queryCount = clone $query;
        $queryCount->select("{$queryCount->tableName}.id");
        $queryCount->groupBy("{$queryCount->tableName}.id");
        $dataProvider->totalCount = $queryCount->count();
		
        return $dataProvider;

        //demeanors
    }

    public function searchDraft($params)
    {
        $models = Draft::getModels(UserForm::className());

        $dataProvider = new ArrayDataProvider([
            'allModels' => $models,
            'modelClass' => UserForm::className(),
            'pagination' => [
                'pageSizeLimit' => [1, 200]
            ],
        ]);
		
        return $dataProvider;
    }

    /**
     * @return UserSearchActiveQuery $this
     */
    public static function find()
    {
        $query = new UserSearchActiveQuery('app\modules\users\models\ar\User');
		
        return $query->addAccessConditions();
    }

    /**
     * Возвращает список водителей.
     * @return array
     */
    public static function getList($formatJson = false)
    {
        $result = [];
        foreach (self::getDriversObjectsList()/*getObjectsList()*/ as $row) {
            if ($formatJson) {
                $result[] = [
                    'id' => $row['id'],
                    'name' => $row['name']
                ];
            } else {
				$result[$row['id']] = $row['name'];
			}
        }
		
        return $result;
    }

    /**
     * Возвращает список всех пользователей.
     * @return array
     */
    public static function getListAll($formatJson = false)
    {
        $result = [];
        foreach (self::getObjectsList() as $row) {
            if ($formatJson) {
                $result[] = [
                    'id' => $row['id'],
                    'name' => $row['fullname']
                ];
            } else {
				$result[$row['id']] = $row['fullname'];
			}
        }
		
        return $result;
    }

    /**
     * Возвращает список всех пользоватлей в виде списка массивов с набором полей.
     * @return array
     */
    public static function getObjectsList()
    {
        return self::find()
			->select(['users.id', "CONCAT(profiles.surname, ' ', profiles.name, ' ', profiles.patronymic) as fullname"])
            ->innerJoin('core.profiles', 'core.profiles.user_id = users.id')
			->asArray()
			->all() ? : [];
    }

    /**
     * Возвращает список водителей.
     * @return array
     */
    public static function getDrivers()
    {
        $result = [];
        foreach (self::getDriversObjectsList() as $row) {
            $result[$row['id']] = $row['name'];
        }
		
        return $result;
    }

    /**
     * Возвращает список водителей в виде списка массивов с набором полей.
     * @return array
     */
    public static function getDriversObjectsList()
    {
        return self::find()
			->drivers()
			->innerJoin('core.profiles', 'profiles.user_id = users.id')
            ->select(['users.id', "CONCAT(profiles.surname, ' ', profiles.name, ' ', profiles.patronymic) as name"])
            ->asArray()
			->all() ?: [];
    }

    /**
     * Возвращает список идентификаторов.
     * @return array
     */
    public static function getIds()
    {
        $query = self::find();
        $query->select(['users.id']);
		
        $result = [];
        foreach ($query->asArray()->each() as $row) {
            $result[] = $row['id'];
        }
		
        return $result;
    }

    /**
     * Возвращает список идентификаторов водителей.
     * @return array
     */
    public static function getDriversIds()
    {
        $query = self::find()->drivers();
        $query->select(['users.id']);
        $result = [];
		
        foreach ($query->asArray()->each() as $row) {
            $result[] = $row['id'];
        }
		
        return $result;
    }

    /**
     * Конвертация формата даты.
     * @param $value дата в формате d.m.Y
     * @return false|null|string дат в формате Y-m-d
     */
    protected function dateDbFormat($value)
    {
        if ($value) {
            return date('Y-m-d', strtotime($value));
        }
		
        return null;
    }

    /**
     * Возвращает cписок emails
     * @return array
     */
    public static function getEmailList($search = null, $limit = null)
    {
        $query = self::find();

        if ($search) {
            $searchStrings = explode(' ', $search);
            $filterWhere = ['OR'];
            foreach ($searchStrings as $searchString) {
                $filterWhere[] = ['ilike', 'system.users.email', $searchString];
            }
			
            $query->andFilterWhere($filterWhere);
        }

        if ($limit) {
            $query->limit($limit);
        }

        $models = $query->all();
        $result = [];
        foreach ($models as $model) {
            $result[] = [
                'id' => $model->id,
                'text' => $model->email,
            ];
        }

        return $result;
    }

    /**
     * Возвращает cписок уникальных местоположений
     * @return array
     */
    public static function getLocationsList($search = null, $limit = null)
    {
        $modelQuery = self::find()->joinWith(['profile'])->select('core.profiles.location');

        if ($search) {
            $searchStrings = explode(' ', $search);
            $filterWhere = ['OR'];
            foreach ($searchStrings as $searchString) {
                $filterWhere[] = ['ilike', 'core.profiles.location', $searchString];
            }
            $modelQuery->andFilterWhere($filterWhere);
        }

        if ($limit) {
            $modelQuery->limit($limit);
        }

        $command = Yii::$app->db->createCommand('SELECT DISTINCT INITCAP(location) AS location FROM (' . $modelQuery->createCommand()->getRawSql() . ') AS t');

        $models = $command->queryAll();

        $result = [];

        foreach ($models as $model) {
            $result[] = [
                'id' => $model['location'],
                'text' => $model['location'],
            ];
        }

        return $result;
    }

    /**
     * Возвращает cписок уникальных должностей
     * @return array
     */
    public static function getPostsList($search = null, $limit = null)
    {
        $modelQuery = self::find()->joinWith(['profile'])->select('core.profiles.post');

        if ($search) {
            $searchStrings = explode(' ', $search);
            $filterWhere = ['OR'];
            foreach ($searchStrings as $searchString) {
                $filterWhere[] = ['ilike', 'core.profiles.post', $searchString];
            }
            $modelQuery->andFilterWhere($filterWhere);
        }

        if ($limit) {
            $modelQuery->limit($limit);
        }

        $command = Yii::$app->db->createCommand('SELECT DISTINCT INITCAP(post) AS post FROM (' . $modelQuery->createCommand()->getRawSql() . ') AS t');
        $models = $command->queryAll();

        $result = [];

        foreach ($models as $model) {
            $result[] = [
                'id' => $model['post'],
                'text' => $model['post'],
            ];
        }

        return $result;
    }


    /**
     * Возвращает cписок уникальных бэндов
     * @return array
     */
    public static function getBandsList($search = null, $limit = null)
    {
        $modelQuery = self::find()->joinWith(['profile'])->select('core.profiles.band');

        if ($search) {
            $searchStrings = explode(' ', $search);
            $filterWhere = ['OR'];
            foreach ($searchStrings as $searchString) {
                $filterWhere[] = ['ilike', 'core.profiles.band', $searchString];
            }
            $modelQuery->andFilterWhere($filterWhere);
        }

        if ($limit) {
            $modelQuery->limit($limit);
        }

        $command = Yii::$app->db->createCommand('SELECT DISTINCT UPPER(band) AS band FROM (' . $modelQuery->createCommand()->getRawSql() . ') AS t');
        $models = $command->queryAll();

        $result = [];

        foreach ($models as $model) {
            $result[] = [
                'id' => $model['band'],
                'text' => $model['band'],
            ];
        }

        return $result;
    }

    /**
     * Возвращает cписок wwwid
     * @return array
     */
    public static function getWwidList($search = null, $limit = null)
    {
        $query = self::find()->joinWith('profile');

        if ($search) {
            $searchStrings = explode(' ', $search);
            $filterWhere = ['OR'];
            foreach ($searchStrings as $searchString) {
                $filterWhere[] = ['ilike', 'core.profiles.wwid', $searchString];
            }
            $query->andFilterWhere($filterWhere);
        }

        if ($limit) {
            $query->limit($limit);
        }

        $models = $query->all();
        $result = [];

        foreach ($models as $model) {
            if (!$model->wwid) continue;
            $result[] = [
                'id' => $model->id,
                'text' => $model->wwid,
            ];
        }

        return $result;
    }

    /**
     * @param $string
     * @return array
     */
    public static function getListByWwidOrFullname($string, $pagination = [])
    {

        $query = self::find()->joinWith('profile');
        if ($string) {
            $searchStrings = explode(' ', $string);
            foreach ($searchStrings as $searchString) {
                $query->andFilterWhere([
                    'or',
                    ['wwid' => $searchString],
                    ['ilike', 'core.profiles.name', $searchString],
                    ['ilike', 'core.profiles.surname', $searchString],
                    ['ilike', 'core.profiles.patronymic', $searchString],
                ]);
            }
        }

        if (!empty($pagination)) {
            if (isset($pagination['page'])) {
                $query->offset((intval($pagination['page']) - 1) * intval($pagination['pageSize']));
            }

            if (isset($pagination['pageSize'])) {
                $query->limit(intval($pagination['pageSize']));
            }
        }


        return $query->asArray()->all();
    }

    /**
     * @param $wwid
     * @return array|null|\yii\db\ActiveRecord
     */
    public static function getByWwid($wwid)
    {
        return self::find()->joinWith('profile')->where(['core.profiles.wwid' => $wwid])->one();
    }

    /**
     * @param $demeanorName
     * @return array
     */
    public static function getListByDemeanor($demeanorName)
    {
        return self::find()
            ->alias('u')
            ->select(['u.id', "CONCAT(prof.surname, ' ', prof.name, ' ', prof.patronymic) as fullname"])
            ->innerJoin(['prof' => Profile::tableName()], 'prof.user_id = u.id')
            // поиск по поведению
            ->leftJoin(['aa1' => Assignment::tableName()], "aa1.user_id = u.id")
            ->leftJoin(['ai1' => AuthItem::tableName()], "ai1.id = aa1.item_id AND ai1.type = " . Item::TYPE_DEMEANOR)
            // поиск по роли с таким поведением
            ->leftJoin(['aa2' => Assignment::tableName()], "aa2.user_id = u.id")
            ->leftJoin(['ai2role' => AuthItem::tableName()], "ai2role.id= aa2.item_id AND ai2role.type = " . Item::TYPE_ROLE)
            ->leftJoin(['aic2' => 'system.auth_item_child'], "aic2.parent_id = ai2role.id")
            ->leftJoin(['ai2dem' => AuthItem::tableName()], "aic2.child_id = ai2dem.id AND ai2dem.type = " . Item::TYPE_DEMEANOR)
            ->andWhere(['or', ['ai1.name' => $demeanorName], ['ai2dem.name' => $demeanorName]])
            ->asArray()->all() ?: [];
    }

    /**
     * Returns the driver who drives vehicle at certain point.
     * @param integer $vehicleId vehicle ID
     * @param string $datetime date in DB format ('Y-m-d H:i:s')
     * @return null|User[]
     */
    public static function getDriversByVehicleAndTime($vehicleId, $datetime)
    {
        $query = self::find();
        return $query
			->innerJoin(['wbl' => Waybill::tableName()], "{$query->tableName}.id = wbl.driver_id")
            ->where([
                'and', ['wbl.vehicle_id' => $vehicleId],
                ['and', ['<', 'wbl.date_start', $datetime], ['>', 'wbl.date_end', $datetime]]
            ])
			->all();
    }

    public function exportSearch($params)
    {
        $query = self::find()
			->joinWith(['profile', 'passport', 'vehicles', 'medPolicy', 'driverLicence']);
			
        return $this->_search($query, $params);
    }

    /**
     * @return array yii/grid/DataColumn
     */
    public static function getExportColumns()
    {
        return [
            'wwid',
            'username',
            'profile.surname',
            'profile.name',
            'profile.patronymic',
            'profile.post',
            'profile.phone',
            'email',
            'profile.personal_number',
            'profile.rank',
            'profile.band',
            'profile.working_start:date',
            'profile.experience',
            'profile.working_end:date',
            'profile.location',
            [
                'attribute' => 'profile.leader_one_user_id',
                'value' => function ($model) {
                    return $model->profile && $model->profile->leaderLevelOne ? $model->profile->leaderLevelOne->fullName : '';
                }
            ],
            [
                'attribute' => 'profile.leader_two_user_id',
                'value' => function ($model) {
                    return $model->profile && $model->profile->leaderLevelTwo ? $model->profile->leaderLevelTwo->fullName : '';
                }
            ],
            [
                'attribute' => 'active',
                'value' => function ($model) {
                    return User::attributeValueLabels('active', $model->active);
                }
            ],
            [
                'attribute' => 'statuses',
                'value' => function ($model) {
                    /** @var \app\modules\system\models\Tag $tag */
                    $tag = \app\modules\system\models\Tag::find()->byName('user-statuses')->one();
                    $selectedValues = $tag->getSelectedValues($model->id, $model::className());

                    return implode(', ', ArrayHelper::map($selectedValues, 'id', 'label'));
                },
            ],
            [
                'attribute' => 'roles',
                'value' => function ($model) {
                    return $model->implodedRoles;
                },
            ],
            [
                'attribute' => 'permissions',
                'value' => function ($model) {
                    return $model->implodedPermissions;
                },
            ],
            [
                'attribute' => 'demeanors',
                'value' => function ($model) {
                    return $model->implodedDemeanors;
                },
            ],
            'profile.date_proxy_begin:date',
            'profile.date_proxy_end:date',
            'profile.comment',
            'profile.tp_reference_list',
            'artificialPerson.name:raw',
            'department.name:raw',

            [
                'attribute' => 'costCenter.name',
                'value' => function ($model) {
                    /* @var $model User */

                    return $model->getCostCenterNames();
                },
            ],
            'mrc.name:raw',
            [
                'attribute' => 'passport.birth_date',
                'format' => 'date',
                'label' => UsersModule::t('Паспорт') . ': ' . UsersModule::t('Дата рождения')
            ],
            [
                'attribute' => 'passport.birth_place',
                'label' => UsersModule::t('Паспорт') . ': ' . UsersModule::t('Место рождения')
            ],
            [
                'attribute' => 'passport.number',
                'label' => UsersModule::t('Паспорт') . ': ' . UsersModule::t('Серия/Номер')
            ],
            [
                'attribute' => 'passport.given_by',
                'label' => UsersModule::t('Паспорт') . ': ' . UsersModule::t('Выдано')
            ],
            [
                'attribute' => 'passport.date_issue',
                'format' => 'date',
                'label' => UsersModule::t('Паспорт') . ': ' . UsersModule::t('Дата выдачи')
            ],
            [
                'attribute' => 'passport.registration_address',
                'label' => UsersModule::t('Паспорт') . ': ' . UsersModule::t('Адрес регистрации')
            ],
            [
                'attribute' => 'passport.residence_address',
                'label' => UsersModule::t('Паспорт') . ': ' . UsersModule::t('Место жительства')
            ],
            [
                'attribute' => 'medPolicy.number',
                'label' => UsersModule::t('Мед.справка') . ': ' . UsersModule::t('Серия и номер')
            ],
            [
                'attribute' => 'medPolicy.given_by',
                'label' => UsersModule::t('Мед.справка') . ': ' . UsersModule::t('Выдано')
            ],
            [
                'attribute' => 'medPolicy.date_issue',
                'format' => 'date',
                'label' => UsersModule::t('Мед.справка') . ': ' . UsersModule::t('Дата выдачи'),
            ],
            [
                'attribute' => 'medPolicy.date_expiration',
                'format' => 'date',
                'label' => UsersModule::t('Мед.справка') . ': ' . UsersModule::t('Дата истечения')
            ],
            [
                'attribute' => 'driverLicence.number',
                'label' => UsersModule::t('Водительское удостоверение') . ': ' . UsersModule::t('Серия и номер')
            ],
            [
                'attribute' => 'driverLicence.given_by',
                'label' => UsersModule::t('Водительское удостоверение') . ': ' . UsersModule::t('Выдано')
            ],
            [
                'attribute' => 'driverLicence.date_issue',
                'format' => 'date',
                'label' => UsersModule::t('Водительское удостоверение') . ': ' . UsersModule::t('Дата выдачи')
            ],
            [
                'attribute' => 'driverLicence.category',
                'label' => UsersModule::t('Водительское удостоверение') . ': ' . UsersModule::t('Категория')
            ],
            [
                'attribute' => 'driverLicence.date_expiration',
                'format' => 'date',
                'label' => UsersModule::t('Водительское удостоверение') . ': ' . UsersModule::t('Дата истечения')
            ],
            [
                'attribute' => 'fuelCardsIds',
                'value' => function ($model) {
                    /* @var $model User */
                    return implode(', ', $model->getFuelCardList());
                },
            ],
            [
                'attribute' => 'vehicles',
                'value' => function ($model) {
                    /* @var $model User */
                    return implode(', ', $model->getVehicleList());
                },
            ],

        ];
    }

    /**
     * Список Safe-fleet координаторов для рассылки вида [['email'=>'name'],..]
     * @return array
     */
    public static function getSafeFleetEmailList()
    {
        $safeFleets = self::find()->safeFleets()->all();
        $result = [];
        foreach ($safeFleets as $row) {
            $result[$row->email] = $row->fullName;
        }

        return $result;

    }
}
