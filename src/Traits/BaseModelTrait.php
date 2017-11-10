<?php

namespace Anexia\BaseModel\Traits;

use Anexia\BaseModel\Interfaces\BaseModelInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\RelationNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;

trait BaseModelTrait
{
    /** @var int */
    protected static $pagination = 10;

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
     * Get all model objects (filtered, sorted, paginated, with their included relation objects) from the database.
     *
     * @param array $columns
     * @param array|mixed $preSetFilters
     * @param array|mixed $preSetOrFilters
     * @param array|mixed $preSetIncludes
     * @param array|mixed $preSetSearches
     * @param array|mixed $preSetOrSearches
     * @return \Illuminate\Database\Eloquent\Collection|static[]
     */
    public static function allExtended($columns = ['*'], $preSetFilters = [], $preSetOrFilters = [],
                                       $preSetIncludes = [], $preSetSearches = [], $preSetOrSearches = [])
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
        $pagination = $modelClass::$pagination;

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
            $notEmptyFilters
        );

        /**
         * set pagination
         */
        Paginator::currentPageResolver(function () use ($page) {
            return $page;
        });

        /** @var Builder $query */
        $query = $modelClass::query();

        /**
         * filtering
         */
        $query->where(function (Builder $q) use ($filters, $orFilters, $notEmptyFilters) {
            // add not empty and not null filters
            // (->whereNotNull('field')->where('field', '<>', ''))
            self::addNotEmptyFilters($q, $notEmptyFilters);

            // add filters
            // (->where('field', 'attribute'))
            self::addFilters($q, $filters);

            // add OR filters
            // (->orWhere('field', 'attribute1')->orWhere('field', 'attribute2'))

            self::addOrFilters($q, $orFilters);
        });

        $query->where(function (Builder $q) use ($searches, $orSearches) {
            // add LIKE filters
            // (->where('field', 'LIKE', '%attribute%'))
            self::addSearches($q, $searches);

            // add OR LIKE filters
            // (->orWhere('field', 'LIKE', '%attribute1%')->orWhere('field', 'LIKE', '%attribute2%'))
            self::addOrSearches($q, $orSearches);
        });

        /**
         * sorting
         */
        // add sorting
        // (->orderBy(field, direction))
        self::addSortings($query, $sortings);

        /**
         * pagination
         */
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
     */
    private static function extractFromParams($params, $modelClass, &$page = 1,
                                              &$pagination = 10, &$includes = [], &$sortings = [], &$filters = [],
                                              &$orFilters = [], &$searches = [], &$orSearches = [], &$notEmptyFilters = [])
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
                foreach ($attributes as $attribute) {
                    if (in_array($attribute, $params['not_empty'])) {
                        $notEmptyFilters[$attribute] = $attribute;
                    }
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
                        self::filterRelation($q, $relation, $relAttribute, '', true);
                    } else {
                        $q->whereNotNull($attribute);
                        $q->where($attribute, '<>', '');
                    }
                }
            });
        }
    }

    /**
     * add WHERE conditions to a query
     * to only return results that match certain filter criteria
     * (->where('field', 'attribute'))
     *
     * @param Builder $query
     * @param array $filters
     */
    public static function addFilters(Builder &$query, $filters = [])
    {
        if (!empty($filters)) {
            $query->where(function (Builder $q) use ($filters) {
                foreach ($filters as $attribute => $value) {
                    if (is_int($attribute)) {
                        self::addOrFilters($q, $value);
                    } else {
                        $scopes = explode('.', $attribute);

                        if (count($scopes) > 1) {
                            $relation = $scopes[0];
                            unset($scopes[0]);
                            $relAttribute = array_values($scopes);
                            self::filterRelation($q, $relation, $relAttribute, $value);
                        } else {
                            if (is_array($value)) {
                                $q->where(function (Builder $qu) use ($attribute, $value) {
                                    foreach ($value as $val) {
                                        $qu->orWhere($attribute, $val);
                                    }
                                });
                            } else {
                                $q->where($attribute, $value);
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
     */
    public static function addOrFilters(Builder &$query, $orFilters = [])
    {
        if (!empty($orFilters)) {
            $query->orWhere(function (Builder $q) use ($orFilters) {
                foreach ($orFilters as $attribute => $values) {
                    if (is_int($attribute)) {
                        self::addFilters($q, $values);
                    } else {
                        $scopes = explode('.', $attribute);

                        if (count($scopes) > 1) {
                            $relation = $scopes[0];
                            unset($scopes[0]);
                            $scopes = array_values($scopes);
                            self::orFilterRelation($q, $relation, $scopes, $values);
                        } else {
                            if (is_array($values)) {
                                $q->orWhere(function (Builder $qu) use ($attribute, $values) {
                                    foreach ($values as $value) {
                                        $qu->orWhere($attribute, $value);
                                    }
                                });
                            } else {
                                $q->orWhere($attribute, $values);
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
     */
    public static function addSearches(Builder &$query, $searches = [])
    {
        if (!empty($searches)) {
            $query->where(function (Builder $q) use ($searches) {
                foreach ($searches as $attribute => $value) {
                    if (is_int($attribute)) {
                        self::addOrSearches($q, $value);
                    } else {
                        $scopes = explode('.', $attribute);

                        if (count($scopes) > 1) {
                            $relation = $scopes[0];
                            unset($scopes[0]);
                            $scopes = array_values($scopes);
                            self::searchRelation($q, $relation, $scopes, $value);
                        } else {
                            if (is_array($value)) {
                                $q->where(function (Builder $qu) use ($attribute, $value) {
                                    $connection = $qu->getConnection();

                                    switch (get_class($connection)) {
                                        case \Illuminate\Database\PostgresConnection::class:
                                            foreach ($value as $val) {
                                                $qu->orWhere(DB::Raw($attribute . '::TEXT'), 'ILIKE', $val);
                                            }
                                            break;
                                        default:
                                            foreach ($value as $val) {
                                                $qu->orWhere($attribute, 'LIKE', $val);
                                            }
                                            break;
                                    }
                                });
                            } else {
                                $connection = $q->getConnection();

                                switch (get_class($connection)) {
                                    case \Illuminate\Database\PostgresConnection::class:
                                        $q->where(DB::Raw($attribute . '::TEXT'), 'ILIKE', $value);
                                        break;
                                    default:
                                        $q->where($attribute, 'LIKE', $value);
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
     */
    public static function addOrSearches(Builder &$query, $orSearches = [])
    {
        if (!empty($orSearches)) {
            $query->orWhere(function (Builder $q) use ($orSearches) {
                foreach ($orSearches as $attribute => $values) {
                    if (is_int($attribute)) {
                        self::addSearches($q, $values);
                    } else {
                        $scopes = explode('.', $attribute);

                        if (count($scopes) > 1) {
                            $relation = $scopes[0];
                            unset($scopes[0]);
                            $scopes = array_values($scopes);
                            self::orSearchRelation($q, $relation, $scopes, $values);
                        } else {
                            if (is_array($values)) {
                                $q->orWhere(function (Builder $qu) use ($attribute, $values) {
                                    $connection = $qu->getConnection();

                                    switch (get_class($connection)) {
                                        case \Illuminate\Database\PostgresConnection::class:
                                            foreach ($values as $value) {
                                                $qu->where(DB::Raw($attribute . '::TEXT'), 'ILIKE', $value);
                                            }
                                            break;
                                        default:
                                            foreach ($values as $value) {
                                                $qu->where($attribute, 'LIKE', $value);
                                            }
                                            break;
                                    }
                                });
                            } else {
                                $connection = $q->getConnection();

                                switch (get_class($connection)) {
                                    case \Illuminate\Database\PostgresConnection::class:
                                        $q->orWhere(DB::Raw($attribute . '::TEXT'), 'ILIKE', $values);
                                        break;
                                    default:
                                        $q->orWhere($attribute, 'LIKE', $values);
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
     */
    public static function addSortings(Builder &$query, $sortings = [])
    {
        if (!empty($sortings)) {
            foreach ($sortings as $sortField => $sortDirection) {
                $query->orderBy($sortField, $sortDirection);
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
     */
    private static function filterRelation(Builder &$query, $relation = '', $attribute = [], $value = '', $notEmpty = false)
    {
        // get entity that has relation with certain attribute value
        $query->whereHas($relation, function (Builder $q) use ($attribute, $value, $notEmpty) {
            if (count($attribute) > 1) {
                $relation = $attribute[0];
                unset($attribute[0]);
                $attribute = array_values($attribute);
                self::filterRelation($q, $relation, $attribute, $value, $notEmpty);
            } else {
                if ($notEmpty) {
                    $q->whereNotNull($attribute);
                    $q->where($attribute, '<>', '');
                } else {
                    if (is_array($value)) {
                        $q->where(function (Builder $qu) use ($attribute, $value) {
                            foreach ($value as $val) {
                                $qu->orWhere($attribute[0], $val);
                            }
                        });
                    } else {
                        $q->where($attribute[0], $value);
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
     */
    private static function orFilterRelation(Builder &$query, $relation = '', $attribute = [], $values = [])
    {
        // get entity that has relation with certain attribute value
        $query->orWhereHas($relation, function (Builder $q) use ($relation, $attribute, $values) {
            if (count($attribute) > 1) {
                $relation = $attribute[0];
                unset($attribute[0]);
                $attributes = array_values($attribute);
                self::filterRelation($q, $relation, $attributes, $values);
            } else {
                if (is_array($values)) {
                    $q->where(function (Builder $qu) use ($attribute, $values) {
                        foreach ($values as $value) {
                            $qu->orWhere($attribute[0], $value);
                        }
                    });
                } else {
                    $q->where($attribute[0], $values);
                }
            }
        });
    }

    /**
     * @param Builder $query
     * @param string $relation
     * @param array $attribute
     * @param string $value
     */
    private static function searchRelation(Builder &$query, $relation = '', $attribute = [], $value = '')
    {
        // get entity that has relation with certain attribute LIKE the value
        $query->whereHas($relation, function (Builder $q) use ($attribute, $value) {
            if (count($attribute) > 1) {
                $relation = $attribute[0];
                unset($attribute[0]);
                $attribute = array_values($attribute);
                self::searchRelation($q, $relation, $attribute, $value);
            } else {
                if (is_array($value)) {
                    $q->where(function (Builder $qu) use ($attribute, $value) {
                        $connection = $qu->getConnection();

                        switch (get_class($connection)) {
                            case \Illuminate\Database\PostgresConnection::class:
                                foreach ($value as $val) {
                                    $qu->orWhere(DB::Raw($attribute[0] . '::TEXT'), 'ILIKE', $val);
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
                            $q->where(DB::Raw($attribute[0] . '::TEXT'), 'ILIKE', $value);
                            break;
                        default:
                            $q->where($attribute[0], 'LIKE', $value);
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
     */
    private static function orSearchRelation(Builder &$query, $relation = '', $attribute = [], $values = [])
    {
        // get entity that has relation with certain attribute LIKE the value
        $query->orWhereHas($relation, function (Builder $q) use ($attribute, $values) {
            if (count($attribute) > 1) {
                $relation = $attribute[0];
                unset($attribute[0]);
                $attribute = array_values($attribute);
                self::searchRelation($q, $relation, $attribute, $values);
            } else {
                if (is_array($values)) {
                    $q->where(function (Builder $qu) use ($attribute, $values) {
                        $connection = $qu->getConnection();

                        switch (get_class($connection)) {
                            case \Illuminate\Database\PostgresConnection::class:
                                foreach ($values as $value) {
                                    $qu->orWhere(DB::Raw($attribute[0] . '::TEXT'), 'ILIKE', $value);
                                }
                                break;
                            default:
                                foreach ($values as $value) {
                                    $qu->orWhere($attribute[0], 'LIKE', $value);
                                }
                                break;
                        }
                    });
                } else {
                    $connection = $q->getConnection();

                    switch (get_class($connection)) {
                        case \Illuminate\Database\PostgresConnection::class:
                            $q->where(DB::Raw($attribute[0] . '::TEXT'), 'ILIKE', $values);
                            break;
                        default:
                            $q->where($attribute[0], 'LIKE', $values);
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
     * @return Model
     */
    public static function findExtended($id, $columns = ['*'], $preSetFilters = [], $preSetIncludes = [])
    {
        $request = request();

        $filters = $preSetFilters;
        $includes = $preSetIncludes;
        /** @var Model $modelClass */
        $modelClass = get_called_class();

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
                $query = $modelClass::query()->where('id', $id);

                // add filters
                // (->where('field', 'attribute'))
                self::addFilters($query, $filters);

                /** @var Model $entity */
                $entity = $query->with($formattedIncludes)->firstOrFail($columns);
            } else {
                /** @var Model $entity */
                $entity = $modelClass::with($formattedIncludes)->find($id, $columns);
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
        if (count($this->$relation()) > 0) {
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