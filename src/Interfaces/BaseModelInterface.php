<?php

namespace Anexia\BaseModel\Interfaces;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\RelationNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Lang;

interface BaseModelInterface
{
    /**
     * BaseModel constructor.
     * @param array $attributes
     * @param Model|null $currentUser
     */
    public function __construct(array $attributes = [], Model $currentUser = null);

    /**
     * Can be overwritten to support different auth
     *
     * @return Model|null
     */
    public static function getCurrentAuthUser();

    /**
     * @return array
     */
    public function getUnmodifieable();

    /**
     * @param Model|null $currentUser
     * @return array
     */
    public static function getDefaults(Model $currentUser = null);

    /**
     * @return array
     */
    public static function getDefaultSearch();

    /**
     * @return array
     */
    public static function getDefaultSorting();

    /**
     * @param boolean|false $list
     * @param boolean|true $excludeUnmodifieable
     * @return array
     */
    public static function getAllRelationships($list = false, $excludeUnmodifieable = true);

    /**
     * @param boolean|false $list
     * @return array
     */
    public static function getRelationships($list = false);

    /**
     * @param string $relation
     * @param int|null $id
     * @param bool|false $assign
     * @return BaseModelInterface|null
     */
    public function getRelatedObject($relation, $id = null, &$assign = false);

    /**
     * @param BaseModelInterface $object
     * @param string $relation
     * @param Model|null $relatedObject
     * @param Request|null $request
     * @return bool
     */
    public static function isEditableRelationship(BaseModelInterface $object, $relation, Model $relatedObject = null,
                                                  Request $request = null);

    /**
     * @param $relation
     * @return bool
     */
    public function hasRelationship($relation);

    /**
     * @param bool|true $checkCompletion
     * @param Request|null $request
     * @return array
     */
    public static function getValidationRules($checkCompletion = true, Request $request = null);

    /**
     * Extended validation to check that the $object's contents meet the application's logical requirements
     *
     * @param Model|null $currentUser
     * @throws \Exception
     */
    public function validateAttributeLogic(Model $currentUser = null);

    /**
     * @param bool|true $excludeUnmodifieable
     * @return array
     */
    public function getAllAttributes($excludeUnmodifieable = true);

    /**
     * Get all models (filtered, sorted, paginated, with their included relation objects) from the database.
     *
     * @param Request $request
     * @param array|mixed $preSetFilters
     * @param array|mixed $preSetOrFilters
     * @param array|mixed $preSetIncludes
     * @param array|mixed $preSetSearches
     * @param array|mixed $preSetOrSearches
     * @return \Illuminate\Database\Eloquent\Collection|static[]
     */
    public static function allExtended(Request $request, $preSetFilters = [], $preSetOrFilters = [],
                                       $preSetIncludes = [], $preSetSearches = [], $preSetOrSearches = []
    );

    /**
     * add WHERE conditions to a query
     * to only return results that match certain filter criteria
     * (->where('field', 'attribute'))
     *
     * @param Builder $query
     * @param array $filters
     */
    public static function addFilters(Builder &$query, $filters = []);

    /**
     * add WHERE OR conditions to a query
     * to only return results that match one of many filter criteria
     * (->orWhere('field', 'attribute1')->orWhere('field', 'attribute2'))
     *
     * @param Builder $query
     * @param array $orFilters
     */
    public static function addOrFilters(Builder &$query, $orFilters = []);

    /**
     * add WHERE LIKE conditions to a query
     * to only return results that are like certain filter criteria
     * (->where('field', 'LIKE', '%attribute%'))
     *
     * @param Builder $query
     * @param array $searches
     */
    public static function addSearches(Builder &$query, $searches = []);

    /**
     * add WHERE LIKE OR LIKE conditions to a query
     * to only return restults that are like one of many filter criteria
     * (->orWhere('field', 'LIKE', '%attribute1%')->orWhere('field', 'LIKE', '%attribute2%'))
     *
     * @param Builder $query
     * @param array $orSearches
     */
    public static function addOrSearches(Builder &$query, $orSearches = []);

    /**
     * add ORDER BY commands to a query
     * (->orderBy(field, direction))
     *
     * @param Builder $query
     * @param array $sortings
     */
    public static function addSortings(Builder &$query, $sortings = []);

    /**
     * add related objects to the output
     * (->load('relation'))
     *
     * @param LengthAwarePaginator $paginator
     * @param array $includes
     */
    public static function addIncludes(LengthAwarePaginator &$paginator, $includes = []);

    /**
     * @param $id
     * @param Request $request
     * @param array $preSetFilters
     * @param array $preSetIncludes
     * @return Model
     */
    public static function findExtended($id, Request $request, $preSetFilters = [], $preSetIncludes = []);

    /**
     * @param string $relation
     */
    public function clearRelation($relation = '');

    /**
     * @param string $relation
     * @param Model $relatedObject
     */
    public function unrelate($relation, Model $relatedObject);
}