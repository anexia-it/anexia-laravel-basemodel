<?php

namespace Anexia\BaseModel\Traits;

use Anexia\BaseModel\Interfaces\BaseModelInterface;
use Anexia\Changeset\Changerecord;
use Anexia\Changeset\Changeset;
use Anexia\Changeset\Interfaces\ChangesetUserInterface;
use Anexia\Changeset\ObjectType;
use Anexia\LaravelEncryption\DatabaseEncryption;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\RelationNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;

trait BaseModelTrait
{
    use DatabaseEncryption, DecryptionKeyFromAccessToken;

    /** @var int */
    protected static $pagination = 10;
    /** @var int */
    protected static $maxPagination = 1000;

    /**
     * BaseModel constructor.
     * @param array $attributes
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $defaults = $this::getDefaults();
        foreach ($defaults as $key => $value) {
            if (!isset($attributes[$key]) || empty($attributes[$key])) {
                $this->$key = $value;
            }
        }
    }

    /**
     * empty implementation of abstract method
     */
    protected function getEncryptKey()
    {
        //
    }

    /**
     * override to add special behaviour for encrypted models
     */
    protected static function bootChangesetTrackable()
    {
        static::created(function(Model $model) {
            if (!empty($model->getEncryptedFields())) {
                $model->newEncryptedCreationChangeset($model);
            } else {
                $model->newCreationChangeset($model);
            }
        });

        static::updated(function(Model $model) {
            if (!empty($model->getEncryptedFields())) {
                $model->newEncryptedUpdateChangeset($model);
            } else {
                $model->newUpdateChangeset($model);
            }
        });

        static::deleted(function(Model $model) {
            $model->newDeletionChangeset($model);
        });
    }

    /**
     * Called after the encrypted model was successfully created (INSERTED into database)
     *
     * @param Model $model
     */
    public function newEncryptedCreationChangeset(Model $model)
    {
        $oTModel = new ObjectType();
        $oTModel->setConnection($this->getChangesetConnection());
        $objectType = $oTModel->firstOrCreate(['name' => get_class($model)]);

        $currentUser = $this->getChangesetUser();
        $userName = $currentUser instanceof ChangesetUserInterface ? $currentUser->getUserName() : 'unknown username';
        $actionId = uniqid();
        $changesetType = Changeset::CHANGESET_TYPE_INSERT;
        $attributes = $model->attributes;

        $changeset = new Changeset();
        $changeset->setConnection($this->getChangesetConnection());
        $changeset->action_id = $actionId;
        $changeset->changeset_type = $changesetType;
        $changeset->objectType()->associate($objectType);
        $changeset->object_uuid = $model->id;
        $changeset->user()->associate($currentUser);

        $changeset->display = $this->changesetTypesMap[$changesetType] . ' ' . $objectType->name . ' ' . $model->id
            . ' at date ' . date('Y-m-d H:i:s') . ' by ' . $userName;
        $changeset->save();

        $encryptedAttributes = $model->getEncryptedFields();
        foreach ($attributes as $fieldName => $newValue) {
            if (in_array($fieldName, $this->trackFields)) {
                if (in_array($fieldName, $encryptedAttributes)) {
                    $changerecord = new Changerecord();
                    $changerecord->setConnection($this->getChangesetConnection());
                    $changerecord->display = 'Set ' . $fieldName . ' (encrypted)';
                    $changerecord->field_name = $fieldName;
                    $changerecord->changeset()->associate($changeset);
                    $changerecord->save();
                } else {
                    $newValue = !empty($newValue) ? $newValue : 'NULL';

                    $changerecord = new Changerecord();
                    $changerecord->setConnection($this->getChangesetConnection());
                    $changerecord->display = 'Set ' . $fieldName . ' to ' . $newValue;
                    $changerecord->field_name = $fieldName;
                    $changerecord->new_value = $newValue;
                    $changerecord->changeset()->associate($changeset);
                    $changerecord->save();
                }
            }
        }

        if (!empty($model->trackRelated)) {
            // only create one changeset per each object (collect them to avoid duplicates)
            $handledChanges[$objectType->name][$model->id] = $changesetType;
            $this->manageRelatedChangesets($model, $changeset, $actionId, $changesetType, $currentUser, $handledChanges);
        }
    }

    /**
     * Called after the encrypted model was successfully updated (UPDATED in database)
     *
     * @param Model $model
     */
    public function newEncryptedUpdateChangeset(Model $model)
    {
        $oTModel = new ObjectType();
        $oTModel->setConnection($this->getChangesetConnection());
        $objectType = $oTModel->firstOrCreate(['name' => get_class($model)]);

        $currentUser = $this->getChangesetUser();
        $userName = $currentUser instanceof ChangesetUserInterface ? $currentUser->getUserName() : 'unknown username';
        $actionId = uniqid();
        $changesetType = Changeset::CHANGESET_TYPE_UPDATE;
        $attributes = $model->attributes;

        $changeset = new Changeset();
        $changeset->setConnection($this->getChangesetConnection());
        $changeset->action_id = $actionId;
        $changeset->changeset_type = $changesetType;
        $changeset->objectType()->associate($objectType);
        $changeset->object_uuid = $model->id;
        $changeset->user()->associate($currentUser);

        $changeset->display = $this->changesetTypesMap[$changesetType] . ' ' . $objectType->name . ' ' . $model->id
            . ' at date ' . date('Y-m-d H:i:s') . ' by ' . $userName;
        $changeset->save();

        $encryptedAttributes = $model->getEncryptedFields();
        foreach ($attributes as $fieldName => $newValue) {
            if (in_array($fieldName, $this->trackFields)) {
                if (in_array($fieldName, $encryptedAttributes)) {
                    $changerecord = new Changerecord();
                    $changerecord->setConnection($this->getChangesetConnection());
                    $changerecord->display = 'Changed ' . $fieldName . ' (encrypted)';
                    $changerecord->field_name = $fieldName;
                    $changerecord->changeset()->associate($changeset);
                    $changerecord->save();
                } else {
                    $oldValue = isset($model->original[$fieldName]) && !empty($model->original[$fieldName]) ? $model->original[$fieldName] : 'NULL';
                    $newValue = !empty($newValue) ? $newValue : 'NULL';

                    if ($newValue !== $oldValue) {
                        $changerecord = new Changerecord();
                        $changerecord->setConnection($this->getChangesetConnection());
                        $changerecord->display = 'Changed ' . $fieldName . ' from ' . $oldValue . ' to ' . $newValue;
                        $changerecord->field_name = $fieldName;
                        $changerecord->new_value = $newValue;
                        $changerecord->old_value = $oldValue;

                        $changerecord->changeset()->associate($changeset);
                        $changerecord->save();
                    }
                }
            }
        }

        if (!empty($model->trackRelated)) {
            // only create one changeset per each object (collect them to avoid duplicates)
            $handledChanges[$objectType->name][$model->id] = $changesetType;
            $this->manageRelatedChangesets($model, $changeset, $actionId, $changesetType, $currentUser, $handledChanges);
        }
    }

    /**
     * @param int $pagination
     */
    protected static function setPagination($pagination)
    {
        self::$pagination = $pagination;
    }

    /**
     * Can be overwritten to support different auth
     *
     * @return Model|null
     */
    public static function getCurrentAuthUser()
    {
        $currentUser = null;

        if (Auth::check()) {
            $currentUser = Auth::user();
        }

        return $currentUser;
    }

    /**
     * @return array
     */
    public function getUnmodifieable()
    {
        // return attributes that can not be changed during create/put/patch in each model
        return [];
    }

    /**
     * @return array
     */
    public static function getDefaults()
    {
        // return attributes' default values in each model
        return [];
    }

    /**
     * @return array
     */
    public static function getDefaultSearch()
    {
        // return default sorting in each model
        return [];
    }

    /**
     * @return array
     */
    public static function getDefaultSorting()
    {
        // return default sorting in each model
        return [];
    }

    /**
     * @return array
     */
    public static function getDefaultExport()
    {
        // return the default export columns in each model
        return [];
    }

    /**
     * @return array
     */
    public static function getPreparedFilters()
    {
        // return the specially prepared filters in each model
        return [];
    }

    /**
     * @return array
     */
    public static function getPreparedComplexFilters()
    {
        // return the specially prepared complex filters (adapting the query builder) in each model
        return [];
    }

    /**
     * @param boolean|false $list
     * @param boolean|true $excludeUnmodifieable
     * @return array
     */
    public static function getAllRelationships($list = false, $excludeUnmodifieable = true)
    {
        /** @var BaseModelInterface $modelClass */
        $modelClass = get_called_class();

        /** @var Model $instance */
        $instance = new $modelClass();
        $relationships = $instance::getRelationships($list);

        if (!empty($relationships) && $excludeUnmodifieable) {
            $unmodifieable = $instance->getUnmodifieable();
            if ($list) {
                $relationships = array_diff($relationships, $unmodifieable);
            } else if (!empty($unmodifieable)) {
                $tmpCopy = $relationships;
                foreach ($tmpCopy as $type => $relationship) {
                    foreach ($relationship as $relation => $config) {
                        if (in_array($relation, $unmodifieable)) {
                            unset($relationships[$type][$relation]);
                            if (empty($relationships[$type])) {
                                unset($relationships[$type]);
                            }
                        }
                    }
                }
            }
        }

        return $relationships;
    }

    /**
     * @param boolean|false $list
     * @return array
     */
    public static function getRelationships($list = false)
    {
        // return array of all possible relations in each model
        return [];
    }

    /**
     * @param string $relation
     * @param int|null $id
     * @param bool|false $assign
     * @return Model|null
     */
    public function getRelatedObject($relation, $id = null, &$assign = false)
    {
        $relatedObject = null;
        $relationships = $this::getRelationships();

        if ($id > 0) {
            if (isset($relationships['one'][$relation])) {
                /**
                 * assign the object to the relation if no assignment for this relation exists or
                 * if a different object is currently assigned (has a different id)
                 */
                if (!$this->$relation instanceof BaseModelInterface || $this->$relation->id != $id) {
                    $assign = true;
                }
            } else if (isset($relationships['many'][$relation])) {
                /**
                 * only assign not yet assigned objects to the relation
                 * (avoid duplicate assignments of the same related object)
                 */
                if (!$this->$relation->contains($id)) {
                    $assign = true;
                }
            }

            /**
             * use an existing relationObject
             */
            $relatedObject = $this::getRelationships(true, false)[$relation]::find($id);
        } else if (isset($relationships['one'][$relation])) {
            /**
             * use the assigned relationObject
             */

            $relatedObject = $this->$relation;
        }

        return $relatedObject;
    }

    /**
     * @param BaseModelInterface $object
     * @param string $relation
     * @param Model|null $relatedObject
     * @param array $values
     * @return bool
     */
    public static function isEditableRelationship(BaseModelInterface $object, $relation, Model $relatedObject = null,
                                                  $values = [])
    {
        $relationship = null;
        $relationships = $object::getAllRelationships();

        if ((isset($relationships['many']) && in_array($relation, array_keys($relationships['many'])))) {
            $relationship = $relationships['many'][$relation];
        } else if ((isset($relationships['one']) && in_array($relation, array_keys($relationships['one'])))) {
            $relationship = $relationships['one'][$relation];
        }

        if ($relationship !== null) {
            // relationship is editable by default (nothing else was specified)
            if (!isset($relationship['editable']) || $relationship['editable'] === true) {
                return true;
            }

            if (is_array($relationship['editable'])) {
                $relationAttributes = $relatedObject->getAllAttributes();
                foreach ($relationship['editable'] as $orKey => $ands) {
                    if (count($ands) > 0) {
                        $valid = true;
                        $andKey = 0;
                        while ($valid && $andKey < count($ands)) {
                            try {
                                $condition = $ands[$andKey];
                                $attribute = $condition['attribute'];
                                $value = $condition['value'];
                                $operator = isset($condition['operator']) ? $condition['operator'] : null;

                                if (in_array($attribute, $relationAttributes)) {
                                    switch ($operator) {
                                        default:
                                            if ($relatedObject->$attribute != $value
                                                && (!array_key_exists($attribute, $values)
                                                    || $values[$attribute] != $value)
                                            ) {
                                                $valid = false;
                                            }

                                            break;
                                    }
                                } else {
                                    $valid = false;
                                }

                                $andKey++;
                            } catch (\Exception $e) {
                                $valid = false;
                            }
                        }

                        if ($valid) {
                            // one OR condition was met, relatedObject is editable via $object endpoint
                            return true;
                        }
                    }
                }
            }
        }

        // relatedObject is not editable via $object endpoint
        return false;
    }

    /**
     * @param $relation
     * @return bool
     */
    public function hasRelationship($relation)
    {
        $relationships = $this::getRelationships(true);

        return in_array($relation, array_keys($relationships));
    }

    /**
     * @param bool|true $checkCompletion
     * @return array
     */
    public static function getValidationRules($checkCompletion = true)
    {
        // return array of all validationRules in each model
        return [];
    }

    /**
     * Extended validation to check that the $object's contents meet the application's logical requirements
     *
     * @throws \Exception
     */
    public function validateAttributeLogic()
    {
        // add logical validation of model's attributes in each model
    }

    /**
     * @param bool|true $excludeUnmodifieable
     * @return array
     */
    public function getAllAttributes($excludeUnmodifieable = true)
    {
        /** @var BaseModelInterface $modelClass */
        $modelClass = get_called_class();

        /** @var Model $instance */
        $instance = new $modelClass();
        $fillables = $instance->getFillable();
        $guarded = $instance->getGuarded();
        $attributes = array_merge($fillables, $guarded);

        if ($excludeUnmodifieable) {
            $unmodifieable = $instance->getUnmodifieable();
            $attributes = array_diff($attributes, $unmodifieable);
        }

        return $attributes;
    }

    /**
     * @param array $columns
     * @param string|null $decryptionKey
     * @param array $preSetFilters
     * @param array $preSetOrFilters
     * @param array $preSetIncludes
     * @param array $preSetSearches
     * @param array $preSetOrSearches
     * @param int|string|null $preSetPagination
     * @return \Illuminate\Database\Eloquent\Collection|static[]
     */
    public static function allExtendedEncrypted($columns = ['*'], $decryptionKey = null, $preSetFilters = [],
                                                $preSetOrFilters = [], $preSetIncludes = [], $preSetSearches = [],
                                                $preSetOrSearches = [], $preSetPagination = '')
    {
        return self::allExtended(
            $columns,
            $preSetFilters,
            $preSetOrFilters,
            $preSetIncludes,
            $preSetSearches,
            $preSetOrSearches,
            $preSetPagination,
            $decryptionKey
        );
    }

    /**
     * Get all model objects (filtered, sorted, paginated, with their included relation objects) from the database.
     *
     * @param array $columns
     * @param array|mixed $preSetFilters
     * @param array|mixed $preSetOrFilters
     * @param array|mixed $preSetIncludes
     * @param array|mixed $preSetSearches
     * @param array|mixed $preSetOrSearches
     * @param int|string|null $preSetPagination
     * @param string|null $decryptionKey
     * @return \Illuminate\Database\Eloquent\Collection|static[]
     */
    public static function allExtended($columns = ['*'], $preSetFilters = [], $preSetOrFilters = [],
                                       $preSetIncludes = [], $preSetSearches = [], $preSetOrSearches = [],
                                       $preSetPagination = '', $decryptionKey = null)
    {
        $request = request();

        $filters = $preSetFilters;
        $orFilters = $preSetOrFilters;
        $includes = $preSetIncludes;
        $searches = $preSetSearches;
        $orSearches = $preSetOrSearches;

        /** @var BaseModelInterface $modelClass */
        $modelClass = get_called_class();
        $sortings = $modelClass::getDefaultSorting();
        // use 1 as default page
        $page = 1;

        $pagination = $preSetPagination !== '' ? $preSetPagination : $modelClass::$pagination;

        $getParams = $request->query();
        self::extractFromParams(
            $getParams,
            $modelClass,
            $page,
            $pagination,
            $includes,
            $sortings,
            $filters,
            $orFilters,
            $searches,
            $orSearches,
            $notEmptyFilters,
            $complexFilters
        );

        /**
         * set pagination
         */
        Paginator::currentPageResolver(function () use ($page) {
            return $page;
        });

        if ($decryptionKey) {
            /** @var Builder $query */
            $query = $modelClass::withDecryptKey($decryptionKey);
        } else {
            /** @var Builder $query */
            $query = $modelClass::query();
        }

        /**
         * filtering
         */
        if (!empty($complexFilters)) {
            self::addComplexFilters($query, $complexFilters);
        }
        $query->where(function (Builder $q) use ($filters, $orFilters, $notEmptyFilters, $decryptionKey) {
            // add not empty and not null filters
            // (->whereNotNull('field')->where('field', '<>', ''))
            self::addNotEmptyFilters($q, $notEmptyFilters);

            // add filters
            // (->where('field', 'attribute'))
            self::addFilters($q, $filters, $decryptionKey);

            // add OR filters
            // (->orWhere('field', 'attribute1')->orWhere('field', 'attribute2'))

            self::addOrFilters($q, $orFilters, $decryptionKey);
        });

        $query->where(function (Builder $q) use ($searches, $orSearches, $decryptionKey) {
            // add LIKE filters
            // (->where('field', 'LIKE', '%attribute%'))
            self::addSearches($q, $searches, $decryptionKey);

            // add OR LIKE filters
            // (->orWhere('field', 'LIKE', '%attribute1%')->orWhere('field', 'LIKE', '%attribute2%'))
            self::addOrSearches($q, $orSearches, $decryptionKey);
        });

        /**
         * sorting
         */
        // add sorting
        // (->orderBy(field, direction))
        self::addSortings($query, $sortings, $decryptionKey);

        /**
         * pagination
         */
        if ($pagination === null) {
            $pagination = 10000000;
        }
        // make sure pagination does not exceed the allowed maximum pagination
        $pagination = min($pagination, $modelClass::$maxPagination);
        /** @var LengthAwarePaginator $lAPaginator */
        $lAPaginator = $query->paginate($pagination, $columns);

        /**
         * inclusion
         */
        // include relations over multiple levels
        // (->load('relation'))
        self::addIncludes($lAPaginator, $includes);

        return $lAPaginator;
    }

    /**
     * read the params from the request and divide them into logical information packages
     *
     * @param array $params
     * @param string $modelClass
     * @param int $page; 1 by default
     * @param int $pagination; 10 by default
     * @param array $includes
     * @param array $sortings
     * @param array $filters
     * @param array $orFilters
     * @param array $searches
     * @param array $orSearches
     * @param array $notEmptyFilters
     * @param array $complexFilters
     */
    private static function extractFromParams($params, $modelClass, &$page = 1,
                                              &$pagination = 10, &$includes = [], &$sortings = [], &$filters = [],
                                              &$orFilters = [], &$searches = [], &$orSearches = [],
                                              &$notEmptyFilters = [], &$complexFilters = [])
    {
        if (!empty($params)) {
            /** @var BaseModelInterface $instance */
            $instance = new $modelClass;
            $attributes = $instance->getAllAttributes();

            /**
             * set pagination variables
             */
            if (isset($params['page'])) {
                if (!empty($params['page'])) {
                    $page = $params['page'];
                }
                unset($params['page']);
            }
            if (isset($params['pagination'])) {
                if (!empty($params['pagination'])) {
                    $pagination = $params['pagination'];
                }
                unset($params['pagination']);
            }

            /**
             * set includes
             */
            if (isset($params['include'])) {
                $includes = array_merge($includes, $params['include']);
                $includes = array_unique($includes);
                unset($params['include']);
            }

            /**
             * set sorting
             */
            // if default_sorting is turned off, empty the $sortings array now
            if (isset($params['default_sorting'])
                && ($params['default_sorting'] == false || strtolower($params['default_sorting']) == 'false')
            ) {
                $sortings = [];
                unset($params['default_sorting']);
            }

            // if the given sort_field entries are properties of the entity model, use them for orderBy
            if (isset($params['sort_field']) || isset($params['sort_direction'])) {
                $sortFields = isset($params['sort_field']) ? $params['sort_field'] : [];

                if (isset($params['sort_direction'])) {
                    $sortFields = array_unique(array_merge($sortFields, array_keys($params['sort_direction'])));
                }

                foreach ($sortFields as $key => $sortField) {
                    if (in_array(strtolower($sortField), $attributes)
                        || $sortField == $instance->getKeyName()
                    ) {
                        $direction = (isset($params['sort_direction'][$sortField])
                            && in_array(strtoupper($params['sort_direction'][$sortField]), ['ASC', 'DESC']))
                            ? strtoupper($params['sort_direction'][$sortField]) : 'ASC';
                        if (!isset($sortings[$sortField])) {
                            $sortings[$sortField] = $direction;
                        }
                    }
                }

                unset($params['sort_field']);
                unset($params['sort_direction']);
            }

            /**
             * set search filters
             */
            // LIKE '%value%'
            if (isset($params['search'])) {
                $searchFields = $instance::getDefaultSearch();
                if (is_array($params['search'])) {
                    $defaultValues = [];
                    foreach ($params['search'] as $key => $values) {
                        if (is_array($values)) {
                            if (is_int($key)) {
                                $defaultValues = array_merge($defaultValues, $values);
                            } else {
                                foreach ($values as $value) {
                                    $searches[$key][] = '%' . $value . '%';
                                }
                            }
                        } else {
                            if (is_int($key)) {
                                $defaultValues[] = $values;
                            } else {
                                $searches[$key][] = '%' . $values . '%';
                            }
                        }
                    }
                    $search = [];
                    foreach ($searchFields as $searchField) {
                        foreach ($defaultValues as $values) {
                            $search[$searchField][] = '%' . $values . '%';
                        }
                    }
                    if (!empty($search)) {
                        $searches[] = $search;
                    }
                } else {
                    $search = [];
                    foreach ($searchFields as $searchField) {
                        $search[$searchField][] = '%' . $params['search'] . '%';
                    }
                    if (!empty($search)) {
                        $searches[] = $search;
                    }
                }

                unset($params['search']);
            }
            // LIKE 'value%'
            if (isset($params['search_start'])) {
                $searchFields = $instance::getDefaultSearch();
                if (is_array($params['search_start'])) {
                    $defaultValues = [];
                    foreach ($params['search_start'] as $key => $values) {
                        if (is_array($values)) {
                            if (is_int($key)) {
                                $defaultValues = array_merge($defaultValues, $values);
                            } else {
                                foreach ($values as $value) {
                                    $searches[$key][] = $value . '%';
                                }
                            }
                        } else {
                            if (is_int($key)) {
                                $defaultValues[] = $values;
                            } else {
                                $searches[$key][] = $values . '%';
                            }
                        }
                    }
                    $search = [];
                    foreach ($searchFields as $searchField) {
                        foreach ($defaultValues as $values) {
                            $search[$searchField][] = $values . '%';
                        }
                    }
                    if (!empty($search)) {
                        $searches[] = $search;
                    }
                } else {
                    $search = [];
                    foreach ($searchFields as $searchField) {
                        $search[$searchField][] = $params['search_start'] . '%';
                    }
                    if (!empty($search)) {
                        $searches[] = $search;
                    }
                }

                unset($params['search_start']);
            }
            // LIKE '%value'
            if (isset($params['search_end'])) {
                $searchFields = $instance::getDefaultSearch();
                if (is_array($params['search_end'])) {
                    $defaultValues = [];
                    foreach ($params['search_end'] as $key => $values) {
                        if (is_array($values)) {
                            if (is_int($key)) {
                                $defaultValues = array_merge($defaultValues, $values);
                            } else {
                                foreach ($values as $value) {
                                    $searches[$key][] = '%'. $value;
                                }
                            }
                        } else {
                            if (is_int($key)) {
                                $defaultValues[] = $values;
                            } else {
                                $searches[$key][] = '%'. $values;
                            }
                        }
                    }
                    $search = [];
                    foreach ($searchFields as $searchField) {
                        foreach ($defaultValues as $values) {
                            $search[$searchField][] = '%' . $values;
                        }
                    }
                    if (!empty($search)) {
                        $searches[] = $search;
                    }
                } else {
                    $search = [];
                    foreach ($searchFields as $searchField) {
                        $search[$searchField][] = '%' . $params['search_end'];
                    }
                    if (!empty($search)) {
                        $searches[] = $search;
                    }
                }

                unset($params['search_end']);
            }

            /**
             * set preparedFilters
             */
            $preparedFilters = $modelClass::getPreparedFilters();
            if (isset($params['prepared_filter']) && !empty($preparedFilters)) {
                if (!is_array($params['prepared_filter'])) {
                    $requestedFilters = [$params['prepared_filter']];
                } else {
                    $requestedFilters = $params['prepared_filter'];
                }

                foreach ($requestedFilters as $requestedFilter) {
                    if (isset($preparedFilters[$requestedFilter])) {
                        $prepFilter = $preparedFilters[$requestedFilter];
                        foreach ($prepFilter as $partName => $partValue) {
                            if (is_int($partName)) {
                                $notEmptyFilters[$partValue] = $partValue;
                            } else {
                                $filters[$partName] = $partValue;
                            }
                        }
                    }
                }
            }

            /**
             * set complexFilters
             */
            $prepComplexFilters = $modelClass::getPreparedComplexFilters();
            if (isset($params['prepared_filter']) && !empty($prepComplexFilters)) {
                if (!is_array($params['prepared_filter'])) {
                    $requestedFilters = [$params['prepared_filter']];
                } else {
                    $requestedFilters = $params['prepared_filter'];
                }

                foreach ($requestedFilters as $requestedFilter) {
                    if (isset($prepComplexFilters[$requestedFilter])) {
                        $complexFilters[$requestedFilter] = $prepComplexFilters[$requestedFilter];
                    }
                }
            }

            /**
             * set other filters
             */
            foreach ($attributes as $attribute) {
                if (array_key_exists($attribute, $params)) {
                    $filters[$attribute] = $params[$attribute];
                    unset($params[$attribute]);
                }
            }

            /**
             * set not empty filters
             */
            if (isset($params['not_empty'])) {
                if (is_array($params['not_empty'])) {
                    foreach ($attributes as $attribute) {
                        if (in_array($attribute, $params['not_empty'])) {
                            $notEmptyFilters[$attribute] = $attribute;
                        }
                    }
                } else {
                    $notEmptyFilters[$params['not_empty']] = $params['not_empty'];
                }
                unset($params['not_empty']);
            }
        }
    }

    /**
     * add WHERE conditions to a query
     * to only return results that match certain filter criteria
     * (->whereNotNull('field')->where('field', '<>', ''))
     *
     * @param Builder $query
     * @param array $notEmptyFilters
     */
    public static function addNotEmptyFilters(Builder &$query, $notEmptyFilters = [])
    {
        if (!empty($notEmptyFilters)) {
            $query->where(function (Builder $q) use ($notEmptyFilters) {
                foreach ($notEmptyFilters as $attribute) {

                    $scopes = explode('.', $attribute);

                    if (count($scopes) > 1) {
                        $relation = $scopes[0];
                        unset($scopes[0]);
                        $relAttribute = array_values($scopes);
                        self::filterRelation($q, $relation, $relAttribute, null, true);
                    } else {
                        $q->whereNotNull($attribute);
                        $q->where($attribute, '<>', null);
                    }
                }
            });
        }
    }

    /**
     * add prepared filter function (defined in each model) to the eloquent query builder
     *
     * @param Builder $query
     * @param array $complexFilters
     */
    public static function addComplexFilters(Builder &$query, $complexFilters = [])
    {
        foreach ($complexFilters as $filterName => $complexFilter) {
            foreach ($complexFilter as $command => $filterParams) {
                if (is_array($filterParams)) {
                    $query = call_user_func_array([$query, $command], $filterParams);
                } else {
                    $query->$command($filterParams);
                }
            }
        }
    }

    /**
     * add WHERE conditions to a query
     * to only return results that match certain filter criteria
     * (->where('field', 'attribute'))
     *
     * @param Builder $query
     * @param array $filters
     * @param string|null $decryptionKey
     */
    public static function addFilters(Builder &$query, $filters = [], $decryptionKey = null)
    {
        if (!empty($filters)) {
            $query->where(function (Builder $q) use ($filters, $decryptionKey) {
                foreach ($filters as $attribute => $value) {
                    if (is_int($attribute)) {
                        self::addOrFilters($q, $value, $decryptionKey);
                    } else {
                        $scopes = explode('.', $attribute);

                        if (count($scopes) > 1) {
                            $relation = $scopes[0];
                            unset($scopes[0]);
                            $relAttribute = array_values($scopes);
                            self::filterRelation($q, $relation, $relAttribute, $value, $decryptionKey);
                        } else {
                            if (is_array($value)) {
                                $q->where(function (Builder $qu) use ($attribute, $value, $decryptionKey) {
                                    if ($decryptionKey) {
                                        foreach ($value as $val) {
                                            $qu->orWhereDecrypted($attribute, '=', $val, $decryptionKey);
                                        }
                                    } else {
                                        foreach ($value as $val) {
                                            $qu->orWhere($attribute, $val);
                                        }
                                    }
                                });
                            } else {
                                if ($decryptionKey && in_array($attribute, static::getEncryptedFields())) {
                                    $q->whereDecrypted($attribute, '=', $value, $decryptionKey);
                                } else {
                                    $q->where($attribute, $value);
                                }
                            }
                        }
                    }
                }
            });
        }
    }

    /**
     * add WHERE OR conditions to a query
     * to only return results that match one of many filter criteria
     * (->orWhere('field', 'attribute1')->orWhere('field', 'attribute2'))
     *
     * @param Builder $query
     * @param array $orFilters
     * @param string|null $decryptionKey
     */
    public static function addOrFilters(Builder &$query, $orFilters = [], $decryptionKey = null)
    {
        if (!empty($orFilters)) {
            $query->orWhere(function (Builder $q) use ($orFilters, $decryptionKey) {
                foreach ($orFilters as $attribute => $values) {
                    if (is_int($attribute)) {
                        self::addFilters($q, $values, $decryptionKey);
                    } else {
                        $scopes = explode('.', $attribute);

                        if (count($scopes) > 1) {
                            $relation = $scopes[0];
                            unset($scopes[0]);
                            $scopes = array_values($scopes);
                            self::orFilterRelation($q, $relation, $scopes, $values, $decryptionKey);
                        } else {
                            if (is_array($values)) {
                                $q->orWhere(function (Builder $qu) use ($attribute, $values, $decryptionKey) {
                                    if ($decryptionKey) {
                                        foreach ($values as $value) {
                                            $qu->whereDecrypted($attribute, '=', $value, $decryptionKey);
                                        }
                                    } else {
                                        foreach ($values as $value) {
                                            $qu->where($attribute, $value);
                                        }
                                    }
                                });
                            } else {
                                if ($decryptionKey) {
                                    $q->orWhereDecrypted($attribute, '=', $values, $decryptionKey);
                                } else {
                                    $q->orWhere($attribute, $values);
                                }
                            }
                        }
                    }
                }
            });
        }
    }

    /**
     * add WHERE LIKE conditions to a query
     * to only return results that are (case insensitive) like certain filter criteria
     * (->where('field', 'LIKE', '%attribute%'))
     *
     * @param Builder $query
     * @param array $searches
     * @param string|null $decryptionKey
     */
    public static function addSearches(Builder &$query, $searches = [], $decryptionKey = null)
    {
        if (!empty($searches)) {
            $query->where(function (Builder $q) use ($searches, $decryptionKey) {
                foreach ($searches as $attribute => $value) {
                    if (is_int($attribute)) {
                        self::addOrSearches($q, $value, $decryptionKey);
                    } else {
                        $scopes = explode('.', $attribute);

                        if (count($scopes) > 1) {
                            $relation = $scopes[0];
                            unset($scopes[0]);
                            $scopes = array_values($scopes);
                            self::searchRelation($q, $relation, $scopes, $value, $decryptionKey);
                        } else {
                            if (is_array($value)) {
                                $q->where(function (Builder $qu) use ($attribute, $value, $decryptionKey) {
                                    $connection = $qu->getConnection();

                                    switch (get_class($connection)) {
                                        case \Illuminate\Database\PostgresConnection::class:
                                            if ($decryptionKey) {
                                                foreach ($value as $val) {
                                                    $qu->orWhereDecrypted(DB::Raw($attribute . '::TEXT'), 'ILIKE', $val, $decryptionKey);
                                                }
                                            } else {
                                                foreach ($value as $val) {
                                                    $qu->orWhere(DB::Raw($attribute . '::TEXT'), 'ILIKE', $val);
                                                }
                                            }
                                            break;
                                        default:
                                            if ($decryptionKey) {
                                                foreach ($value as $val) {
                                                    $qu->orWhereDecrypted($attribute, 'LIKE', $val, $decryptionKey);
                                                }
                                            } else {
                                                foreach ($value as $val) {
                                                    $qu->orWhere($attribute, 'LIKE', $val);
                                                }
                                            }
                                            break;
                                    }
                                });
                            } else {
                                $connection = $q->getConnection();

                                switch (get_class($connection)) {
                                    case \Illuminate\Database\PostgresConnection::class:
                                        if ($decryptionKey) {
                                            $q->whereDecrypted(DB::Raw($attribute . '::TEXT'), 'ILIKE', $value, $decryptionKey);
                                        } else {
                                            $q->where(DB::Raw($attribute . '::TEXT'), 'ILIKE', $value);
                                        }
                                        break;
                                    default:
                                        if ($decryptionKey) {
                                            $q->whereDecrypted($attribute, 'LIKE', $value, $decryptionKey);
                                        } else {
                                            $q->where($attribute, 'LIKE', $value);
                                        }
                                        break;
                                }
                            }
                        }
                    }
                }
            });
        }
    }

    /**
     * add WHERE LIKE OR LIKE conditions to a query
     * to only return results that are (case insensitive) like one of many filter criteria
     * (->orWhere('field', 'LIKE', '%attribute1%')->orWhere('field', 'LIKE', '%attribute2%'))
     *
     * @param Builder $query
     * @param array $orSearches
     * @param string|null $decryptionKey
     */
    public static function addOrSearches(Builder &$query, $orSearches = [], $decryptionKey = null)
    {
        if (!empty($orSearches)) {
            $query->orWhere(function (Builder $q) use ($orSearches, $decryptionKey) {
                foreach ($orSearches as $attribute => $values) {
                    if (is_int($attribute)) {
                        self::addSearches($q, $values, $decryptionKey);
                    } else {
                        $scopes = explode('.', $attribute);

                        if (count($scopes) > 1) {
                            $relation = $scopes[0];
                            unset($scopes[0]);
                            $scopes = array_values($scopes);
                            self::orSearchRelation($q, $relation, $scopes, $values, $decryptionKey);
                        } else {
                            if (is_array($values)) {
                                $q->orWhere(function (Builder $qu) use ($attribute, $values, $decryptionKey) {
                                    $connection = $qu->getConnection();

                                    switch (get_class($connection)) {
                                        case \Illuminate\Database\PostgresConnection::class:
                                            if ($decryptionKey) {
                                                foreach ($values as $value) {
                                                    $qu->whereDecrypted(DB::Raw($attribute . '::TEXT'), 'ILIKE', $value, $decryptionKey);
                                                }
                                            } else {
                                                foreach ($values as $value) {
                                                    $qu->where(DB::Raw($attribute . '::TEXT'), 'ILIKE', $value);
                                                }
                                            }
                                            break;
                                        default:
                                            if ($decryptionKey) {
                                                foreach ($values as $value) {
                                                    $qu->whereDecrypted($attribute, 'LIKE', $value, $decryptionKey);
                                                }
                                            } else {
                                                foreach ($values as $value) {
                                                    $qu->where($attribute, 'LIKE', $value);
                                                }
                                            }
                                            break;
                                    }
                                });
                            } else {
                                $connection = $q->getConnection();

                                switch (get_class($connection)) {
                                    case \Illuminate\Database\PostgresConnection::class:
                                        if ($decryptionKey) {
                                            $q->orWhereDecrypted(DB::Raw($attribute . '::TEXT'), 'ILIKE', $values, $decryptionKey);
                                        } else {
                                            $q->orWhere(DB::Raw($attribute . '::TEXT'), 'ILIKE', $values);
                                        }
                                        break;
                                    default:
                                        if ($decryptionKey) {
                                            $q->orWhereDecrypted($attribute, 'LIKE', $values, $decryptionKey);
                                        } else {
                                            $q->orWhere($attribute, 'LIKE', $values);
                                        }
                                        break;
                                }
                            }
                        }
                    }
                }
            });
        }
    }

    /**
     * add ORDER BY commands to a query
     * (->orderBy(field, direction))
     *
     * @param Builder $query
     * @param array $sortings
     * @param string|null $decryptionKey
     */
    public static function addSortings(Builder &$query, $sortings = [], $decryptionKey = null)
    {
        if (!empty($sortings)) {
            if ($decryptionKey) {
                foreach ($sortings as $sortField => $sortDirection) {
                    $query->orderByDecrypted($sortField, $sortDirection, $decryptionKey);
                }
            } else {
                foreach ($sortings as $sortField => $sortDirection) {
                    $query->orderBy($sortField, $sortDirection);
                }
            }
        }
    }

    /**
     * add related objects to the output
     * (->load('relation'))
     *
     * @param LengthAwarePaginator $paginator
     * @param array $includes
     */
    public static function addIncludes(LengthAwarePaginator &$paginator, $includes = [])
    {
        if (!empty($includes)) {
            $camelCasedIncludes = [];
            foreach ($includes as $include) {
                $camelCasedIncludes[] = lcfirst(str_replace('_', '', ucwords($include, '_')));
            }
            $paginator->load($camelCasedIncludes);
        }
    }

    /**
     * @param Builder $query
     * @param string $relation
     * @param array $attribute
     * @param string $value
     * @param boolean|false $notEmpty
     * @param string|null $decryptionKey
     */
    private static function filterRelation(Builder &$query, $relation = '', $attribute = [], $value = '',
                                           $notEmpty = false, $decryptionKey = null)
    {
        // get entity that has relation with certain attribute value
        $query->whereHas($relation, function (Builder $q) use ($attribute, $value, $notEmpty, $decryptionKey) {
            if (count($attribute) > 1) {
                $relation = $attribute[0];
                unset($attribute[0]);
                $attribute = array_values($attribute);
                self::filterRelation($q, $relation, $attribute, $value, $notEmpty, $decryptionKey);
            } else {
                if ($notEmpty) {
                    $q->whereNotNull($attribute);
                    $q->where($attribute, '<>', '');
                } else {
                    if (is_array($value)) {
                        $q->where(function (Builder $qu) use ($attribute, $value, $decryptionKey) {
                            if ($decryptionKey) {
                                foreach ($value as $val) {
                                    $qu->orWhereDecrypted($attribute[0], '=', $val, $decryptionKey);
                                }
                            } else {
                                foreach ($value as $val) {
                                    $qu->orWhere($attribute[0], $val);
                                }
                            }
                        });
                    } else {
                        if ($decryptionKey) {
                            $q->whereDecrypted($attribute[0], '=', $value, $decryptionKey);
                        } else {
                            $q->where($attribute[0], $value);
                        }
                    }
                }
            }
        });
    }

    /**
     * @param Builder $query
     * @param string $relation
     * @param array $attribute
     * @param array $values
     * @param string|null $decryptionKey
     */
    private static function orFilterRelation(Builder &$query, $relation = '', $attribute = [], $values = [],
                                             $decryptionKey = null)
    {
        // get entity that has relation with certain attribute value
        $query->orWhereHas($relation, function (Builder $q) use ($relation, $attribute, $values, $decryptionKey) {
            if (count($attribute) > 1) {
                $relation = $attribute[0];
                unset($attribute[0]);
                $attributes = array_values($attribute);
                self::filterRelation($q, $relation, $attributes, $values, $decryptionKey);
            } else {
                if (is_array($values)) {
                    $q->where(function (Builder $qu) use ($attribute, $values, $decryptionKey) {
                        if ($decryptionKey) {
                            foreach ($values as $value) {
                                $qu->orWhereDecrypted($attribute[0], '=', $value, $decryptionKey);
                            }
                        } else {
                            foreach ($values as $value) {
                                $qu->orWhere($attribute[0], $value);
                            }
                        }
                    });
                } else {
                    if ($decryptionKey) {
                        $q->whereDecrypted($attribute[0], '=', $values, $decryptionKey);
                    } else {
                        $q->where($attribute[0], $values);
                    }
                }
            }
        });
    }

    /**
     * @param Builder $query
     * @param string $relation
     * @param array $attribute
     * @param string $value
     * @param string|null $decryptionKey
     */
    private static function searchRelation(Builder &$query, $relation = '', $attribute = [], $value = '',
                                           $decryptionKey = null)
    {
        // get entity that has relation with certain attribute LIKE the value
        $query->whereHas($relation, function (Builder $q) use ($attribute, $value, $decryptionKey) {
            if (count($attribute) > 1) {
                $relation = $attribute[0];
                unset($attribute[0]);
                $attribute = array_values($attribute);
                self::searchRelation($q, $relation, $attribute, $value, $decryptionKey);
            } else {
                if (is_array($value)) {
                    $q->where(function (Builder $qu) use ($attribute, $value, $decryptionKey) {
                        $connection = $qu->getConnection();

                        switch (get_class($connection)) {
                            case \Illuminate\Database\PostgresConnection::class:
                                if ($decryptionKey) {
                                    foreach ($value as $val) {
                                        $qu->orWhereDecrypted(DB::Raw($attribute[0] . '::TEXT'), 'ILIKE', $val, $decryptionKey);
                                    }
                                } else {
                                    foreach ($value as $val) {
                                        $qu->orWhere(DB::Raw($attribute[0] . '::TEXT'), 'ILIKE', $val);
                                    }
                                }
                                break;
                            default:
                                foreach ($value as $val) {
                                    $qu->orWhere($attribute[0], 'LIKE', $val);
                                }
                                break;
                        }
                    });
                } else {
                    $connection = $q->getConnection();

                    switch (get_class($connection)) {
                        case \Illuminate\Database\PostgresConnection::class:
                            if ($decryptionKey) {
                                $q->whereDecrypted(DB::Raw($attribute[0] . '::TEXT'), 'ILIKE', $value, $decryptionKey);
                            } else {
                                $q->where(DB::Raw($attribute[0] . '::TEXT'), 'ILIKE', $value);
                            }
                            break;
                        default:
                            if ($decryptionKey) {
                                $q->whereDecrypted($attribute[0], 'LIKE', $value, $decryptionKey);
                            } else {
                                $q->where($attribute[0], 'LIKE', $value);
                            }
                            break;
                    }
                }
            }
        });
    }

    /**
     * @param Builder $query
     * @param string $relation
     * @param array $attribute
     * @param array $values
     * @param string|null $decryptionKey
     */
    private static function orSearchRelation(Builder &$query, $relation = '', $attribute = [], $values = [],
                                             $decryptionKey = null)
    {
        // get entity that has relation with certain attribute LIKE the value
        $query->orWhereHas($relation, function (Builder $q) use ($attribute, $values, $decryptionKey) {
            if (count($attribute) > 1) {
                $relation = $attribute[0];
                unset($attribute[0]);
                $attribute = array_values($attribute);
                self::searchRelation($q, $relation, $attribute, $values, $decryptionKey);
            } else {
                if (is_array($values)) {
                    $q->where(function (Builder $qu) use ($attribute, $values, $decryptionKey) {
                        $connection = $qu->getConnection();

                        switch (get_class($connection)) {
                            case \Illuminate\Database\PostgresConnection::class:
                                if ($decryptionKey) {
                                    foreach ($values as $value) {
                                        $qu->orWhereDecrypted(DB::Raw($attribute[0] . '::TEXT'), 'ILIKE', $value, $decryptionKey);
                                    }
                                } else {
                                    foreach ($values as $value) {
                                        $qu->orWhere(DB::Raw($attribute[0] . '::TEXT'), 'ILIKE', $value);
                                    }
                                }
                                break;
                            default:
                                if ($decryptionKey) {
                                    foreach ($values as $value) {
                                        $qu->orWhereDecrypted($attribute[0], 'LIKE', $value, $decryptionKey);
                                    }
                                } else {
                                    foreach ($values as $value) {
                                        $qu->orWhere($attribute[0], 'LIKE', $value);
                                    }
                                }
                                break;
                        }
                    });
                } else {
                    $connection = $q->getConnection();

                    switch (get_class($connection)) {
                        case \Illuminate\Database\PostgresConnection::class:
                            if ($decryptionKey) {
                                $q->whereDecrypted(DB::Raw($attribute[0] . '::TEXT'), 'ILIKE', $values, $decryptionKey);
                            } else {
                                $q->where(DB::Raw($attribute[0] . '::TEXT'), 'ILIKE', $values);
                            }
                            break;
                        default:
                            if ($decryptionKey) {
                                $q->whereDecrypted($attribute[0], 'LIKE', $values, $decryptionKey);
                            } else {
                                $q->where($attribute[0], 'LIKE', $values);
                            }
                            break;
                    }
                }
            }
        });
    }

    /**
     * Get a single model object from the database, if it matches the $id and possibly given $preSetFilters (possibly
     * including some/all object's relations)
     *
     * @param int $id
     * @param array $columns
     * @param array $preSetFilters
     * @param array $preSetIncludes
     * @param int|string|null $preSetPagination
     * @param string|null $decryptionKey
     * @return Model
     */
    public static function findExtended($id, $columns = ['*'], $preSetFilters = [], $preSetIncludes = [],
                                        $preSetPagination = '', $decryptionKey = null)
    {
        $request = request();

        $filters = $preSetFilters;
        $includes = $preSetIncludes;
        /** @var Model $modelClass */
        $modelClass = get_called_class();

        $pagination = $preSetPagination !== '' ? $preSetPagination : $modelClass::$pagination;

        $getParams = $request->query();
        self::extractFromParams(
            $getParams,
            $modelClass,
            $page,
            $pagination,
            $includes,
            $sortings,
            $filters,
            $orFilters,
            $searches,
            $orSearches
        );

        // include relations over multiple levels (->load('relation'))
        $formattedIncludes = [];
        if (!empty($includes)) {
            foreach ($includes as $include) {
                $formattedIncludes[] = lcfirst(str_replace('_', '', ucwords($include, '_')));
            }
        }

        // only return results that match certain filter criteria (->where('field', 'attribute'))
        try {
            /**
             * filtering
             */
            if (!empty($filters)) {
                if ($decryptionKey) {
                    $query = $modelClass::withDecryptKey($decryptionKey)->whereDecrypted('id', '=', $id, $decryptionKey);
                } else {
                    $query = $modelClass::query()->where('id', $id);
                }

                // add filters
                // (->where('field', 'attribute'))
                self::addFilters($query, $filters);

                /** @var Model $entity */
                $entity = $query->with($formattedIncludes)->firstOrFail($columns);
            } else {
                if ($decryptionKey) {
                    /** @var Model $entity */
                    $entity = $modelClass::withDecryptKey($decryptionKey)->with($formattedIncludes)->find($id, $columns);
                } else {
                    /** @var Model $entity */
                    $entity = $modelClass::with($formattedIncludes)->find($id, $columns);
                }
            }
        } catch (RelationNotFoundException $e) {
            $message = Lang::get(
                'extended_model.errors.missing_relations',
                ['relations' => implode(',', $formattedIncludes), 'model' => $modelClass]
            );
            throw new RelationNotFoundException($message);
        }

        return $entity;
    }

    /**
     * @param string $relation
     * @throws \Exception
     */
    public function clearRelation($relation = '')
    {
        if (isset($this->$relation) && count($this->$relation) > 0) {
            // "dissociate" all models related via $relation
            switch (get_class($this->$relation())) {
                case HasOne::class:
                    $this->$relation()->delete();

                    break;

                case BelongsTo::class:
                    $this->$relation()->dissociate();

                    break;

                case HasMany::class:
                    $inverse = $this::getRelationships()['many'][$relation]['inverse'];

                    foreach ($this->$relation as $key => $relatedObject) {
                        $relatedObject->$inverse()->dissociate();
                        $this->$relation->forget($key);
                        $relatedObject->save();
                    }

                    // renew the $relation Collection's index-keys
                    $this->refresh();

                    break;

                case BelongsToMany::class:
                    foreach ($this->$relation as $key => $relatedObject) {
                        $this->$relation()->detach($relatedObject->id);
                    }

                    // renew the $relation Collection's index-keys
                    $this->refresh();

                    break;

                default:
                    throw new \Exception(Lang::get(
                        'extended_model.invalid_relation_type',
                        ['relationType' => get_class($this->$relation())]
                    ));

                    break;
            }

            $this->save();
        }
    }

    /**
     * @param $relation
     * @param Model $relatedObject
     * @throws \Exception
     */
    public function unrelate($relation, Model $relatedObject)
    {
        switch (get_class($this->$relation())) {
            case HasOne::class:
                $this->$relation()->delete();

                break;

            case BelongsTo::class:
                $this->$relation()->dissociate();

                break;

            case HasMany::class:
                $inverse = $this::getRelationships()['many'][$relation]['inverse'];

                foreach ($this->$relation as $key => $object) {
                    if ($object->id == $relatedObject->id) {
                        $relatedObject->$inverse()->dissociate();
                        $relatedObject->save();

                        $this->$relation->forget($key);
                    }
                }

                // renew the $relation Collection's index-keys
                $this->refresh();

                break;

            case BelongsToMany::class:
                $this->$relation()->detach($relatedObject->id);

                // renew the $relation Collection's index-keys
                $this->refresh();

                break;

            default:
                throw new \Exception(Lang::get(
                    'extended_model.invalid_relation_type',
                    ['relationType' => get_class($this->$relation())]
                ));

                break;
        }
    }
}