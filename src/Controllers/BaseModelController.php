<?php

namespace Anexia\BaseModel\Controllers;

use Anexia\BaseModel\Interfaces\BaseModelInterface;
use Anexia\BaseModel\Database\Connection;
use Anexia\BaseModel\Exceptions\Query\SqlException;
use Anexia\BaseModel\Exceptions\Validation\BulkValidationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\ValidationException;
use PDF;

class BaseModelController extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    /** string */
    const EXPORT_TYPE_PDF = 'pdf';

    /** string */
    const VIEW_TYPE_DOWNLOAD = 'download';
    /** string */
    const VIEW_TYPE_VIEW = 'view';

    protected $transactionCount = 0;

    /** @var array */
    protected $pdfConfig = [
        'default_font_size' => '8',
        'default_font' => 'sans-serif',
    ];

    /**
     * Recursively manage relations according to submitted request data
     *
     * @param BaseModelInterface $object
     * @param array $requestParams
     * @param bool|true $checkCompletion
     * @param bool|true $manageTransaction
     * @param array $relationsToLoad
     * @throws BulkValidationException
     */
    protected function editObjectContents(BaseModelInterface &$object, $requestParams = [], $checkCompletion = true,
                                          $manageTransaction = true, &$relationsToLoad = [])
    {
        /** @var Model $curUser */
        $curUser = request()->user();
        /** @var array $errorMessages */
        $errorMessages = [];

        /** @var Connection $connection */
        $connection = DB::connection();

        if ($manageTransaction) {
            // start a db transaction (possibly nested)
            $connection->beginTransaction();
        }

        try {
            /**
             * fill all modifiable attributes with sent parameter values
             */
            $this->setObjectAttributes(
                $object,
                $requestParams,
                $object::getValidationRules($checkCompletion)
            );

            /**
             * recursively add/fill/remove all required relations
             */
            $this->manageRequiredRelations($object, $requestParams, $errorMessages, $relationsToLoad);

            /**
             * save the object (it has an id from here on out, necessary for the optional relations)
             */
            $object->save();

            /**
             * recursively add/fill/remove all optional (not required) relations
             */
            $this->manageOptionalRelations($object, $requestParams, $errorMessages, $relationsToLoad);

            /**
             * check content logic
             * @throws ValidationException
             * @throws ModelNotFoundException
             */
            $object->validateAttributeLogic();

            if (!empty($relationsToLoad)) {
                $this->loadRelations($object, $relationsToLoad);
            }

            // save again to keep possible changes from validateAttributeLogic method
            $object->save();
        } catch (QueryException $e) {
            $this->handleModelQueryException($e, $errorMessages, get_class($object));
        } catch (BulkValidationException $e) {
            $messages = $e->getMessages();
            $errorMessages = array_merge($errorMessages, $messages);
        } catch (ValidationException $e) {
            $messages = $e->validator->getMessageBag()->toArray();
            $errorMessages = array_merge($errorMessages, $messages);
        } catch (\Exception $e) {
            $errorMessages[] = $e->getMessage();
        }

        if (!empty($errorMessages)) {
            if ($manageTransaction) {
                // rollback all db changes since (possibly nested) transaction started
                $connection->rollBack();
            }

            // throw exception with all collected error messages
            throw new BulkValidationException($errorMessages);
        }

        if ($manageTransaction) {
            // commit all db changes since (possibly nested) transaction started
            $connection->commit();
        }
    }

    /**
     * @param QueryException $e
     * @param array $errorMessages
     * @param string $modelClass
     */
    protected function handleModelQueryException(QueryException $e, &$errorMessages, $modelClass)
    {
        // 23x = Integrity Constraint Violation
        // 25x = Invalid Transaction State
        if (0 === strpos($e->getCode(), '23') || 0 === strpos($e->getCode(), '25')) {
            $sqle = new SqlException();
            $sqle->setMessageBySqlCode($e->getCode());
            $msg = Lang::get(
                'extended_model.errors.saving_failed',
                ['model' => $modelClass, 'values' => json_encode($e->getBindings())]
            );

            if (isset($errorMessages[$modelClass])) {
                if (is_array($errorMessages[$modelClass])) {
                    array_push($errorMessages[$modelClass], $msg);
                } else {
                    $errorMessages[$modelClass] = [$errorMessages[$modelClass], $msg];
                }
            } else {
                $errorMessages[$modelClass] = $msg;
            }
        } else {
            $errorMessages[] = $e->getMessage();
        }
    }

    /**
     * @param BaseModelInterface $object
     * @param array $relations
     */
    private function loadRelations(BaseModelInterface &$object, $relations = [])
    {
        $loadStrings = [];
        foreach ($relations as $relation => $array) {
            $loadString = $relation;
            $loadStrings[] = $loadString;
            if (!empty($array)) {
                foreach ($array as $subRelation => $subArray) {
                    $relationLoadString = $loadString . '.' . $subRelation;
                    $loadStrings[] = $relationLoadString;
                    $this->recursivelyAddRelationsToLoadString($loadStrings, $relationLoadString, $subArray);
                }

                $loadStrings = array_unique($loadStrings);
            }
        }

        if (!empty($loadStrings)) {
            $object->load($loadStrings);
        }
    }

    /**
     * @param array $loadStrings
     * @param string $loadString
     * @param array $relations
     */
    private function recursivelyAddRelationsToLoadString(&$loadStrings = [], &$loadString = '', $relations = [])
    {
        foreach ($relations as $relation => $array) {
            $subLoadString = $loadString . '.' . $relation;
            $loadStrings[] = $subLoadString;
            if (!empty($array)) {
                $this->recursivelyAddRelationsToLoadString($loadStrings, $subLoadString, $array);
            }
        }
    }

    /**
     * Loop through all relations given in the request that are required (not nullable) for the object
     *
     * @param BaseModelInterface $object
     * @param array $requestParams
     * @param array $errorMessages
     * @param array $relationsToLoad
     */
    private function manageRequiredRelations(BaseModelInterface &$object, $requestParams = [], &$errorMessages,
                                             &$relationsToLoad
    )
    {
        /** @var Model $curUser */
        $curUser = request()->user();
        /** @var array $relationships - all possible relations of the $object */
        $relationships = $object::getAllRelationships();

        /**
         * manage required to-one relations of the $relatedObject
         * (Note: to-many relationships will never be required to save the object)
         *
         * @var string $relation - current relation name from request
         * @var array $values - current relation values (attributes) from request
         */
        foreach ($requestParams as $relation => $values) {
            // if parameter ends with _id convert it to relation and add the id to $values
            $origLength = strlen($relation);
            $relation = preg_replace('/\_id$/','',$relation);
            if (strlen($relation) !== $origLength) {
                if ($values > 0) {
                    $values = ['id' => $values];
                } else {
                    $values = [];
                }
            }

            $relation = lcfirst(str_replace('_', '', ucwords($relation, '_')));
            if (isset($relationships['one']) && in_array($relation, array_keys($relationships['one']))
                && isset($relationships['one'][$relation]['nullable'])
                && $relationships['one'][$relation]['nullable'] == false
            ) {
                /**
                 * add a single new object (to-one-relation)
                 */

                /**
                 * manage the current relation for the object
                 */
                $managedRelationIds = [];
                $relationsToLoad[$relation] = [];
                $this->recursivelyManageRelation(
                    $object,
                    $curUser,
                    $errorMessages,
                    $relation,
                    $relationships['one'][$relation],
                    $values,
                    $managedRelationIds,
                    $relationsToLoad[$relation]
                );
            }
        }
    }

    /**
     * Loop through all relations given in the request that are optional (nullable) for the object
     *
     * @param BaseModelInterface $object
     * @param array $requestParams
     * @param array $errorMessages
     * @param array $relationsToLoad
     */
    private function manageOptionalRelations(BaseModelInterface &$object, $requestParams = [], &$errorMessages,
                                             &$relationsToLoad
    )
    {
        /** @var Model $curUser */
        $curUser = request()->user();
        /** @var array $relationships - all possible relations of the $object */
        $relationships = $object::getAllRelationships();

        /**
         * remove _id params if their corresponding relationship is given
         */
        $prettyParams = [];
        foreach ($requestParams as $relation => $values) {
            // if parameter ends with _id convert it to relation and add the id to $values
            $origLength = strlen($relation);
            $relation = preg_replace('/\_id$/','',$relation);
            if (strlen($relation) !== $origLength) {
                if ($values > 0) {
                    $values = ['id' => $values];
                } else {
                    $values = [];
                }
            } else {
                if (is_array($values) && array_key_exists('id', $values)) {
                    if (!$values['id'] > 0) {
                        unset($values['id']);
                    }
                }
            }

            if (isset($prettyParams[$relation])) {
                foreach ($values as $k => $v) {
                    $prettyParams[$relation][$k] = $v;
                }
            } else {
                $prettyParams[$relation] = $values;
            }
        }

        /**
         * manage optional relations of the $relatedObject
         *
         * @var string $relation - current relation name from request
         * @var array $values - current relation values (attributes) from request
         */
        foreach ($prettyParams as $relation => $values) {
            $relation = lcfirst(str_replace('_', '', ucwords($relation, '_')));
            /**
             * to-one relations
             */
            if (isset($relationships['one']) && in_array($relation, array_keys($relationships['one']))
                && (!isset($relationships['one'][$relation]['nullable'])
                    || $relationships['one'][$relation]['nullable'] == true)
            ) {
                /**
                 * manage a single object relation (to-one-relation)
                 */

                $managedRelationIds = [];
                $relationsToLoad[$relation] = [];
                // if relation from request is empty, remove the related model from the object
                if (empty($values)) {
                    $object->clearRelation($relation);
                } else {
                    $this->recursivelyManageRelation(
                        $object,
                        $curUser,
                        $errorMessages,
                        $relation,
                        $relationships['one'][$relation],
                        $values,
                        $managedRelationIds,
                        $relationsToLoad[$relation]
                    );
                }
            }

            /**
             * to-many relations
             */
            if (isset($relationships['many']) && in_array($relation, array_keys($relationships['many']))
                && (!isset($relationships['many'][$relation]['nullable'])
                    || $relationships['many'][$relation]['nullable'] == true)
            ) {
                /**
                 * manage a multiple objects relation (to-many-relation)
                 */

                // if relation from request is empty, remove all existing related models from the object
                if (empty($values)) {
                    $object->clearRelation($relation);
                } else {
                    /**
                     * manage each of the to-many relations separately
                     */

                    $managedRelationIds = [];
                    $relationsToLoad[$relation] = [];

                    if (!is_int(array_keys($values)[0])) {
                        // only one to many values set given
                        $this->recursivelyManageRelation(
                            $object,
                            $curUser,
                            $errorMessages,
                            $relation,
                            $relationships['many'][$relation],
                            $values,
                            $managedRelationIds,
                            $relationsToLoad[$relation]
                        );
                    } else {
                        // manage all related objects at once
                        foreach ($values as $singleRelationValues) {
                            $this->recursivelyManageRelation(
                                $object,
                                $curUser,
                                $errorMessages,
                                $relation,
                                $relationships['many'][$relation],
                                $singleRelationValues,
                                $managedRelationIds,
                                $relationsToLoad[$relation]
                            );
                        }
                    }

                    /**
                     * delete all related objects from the relation that were not part of the request
                     */
                    if ($object->$relation->count() > 0) {
                        /** @var BaseModelInterface $relatedObject */
                        foreach ($object->$relation as $relatedObject) {
                            if (!in_array($relatedObject->id, $managedRelationIds)) {
                                $object->unrelate($relation, $relatedObject);
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Recursively add and edit a new/existing object to a relations according to submitted request data
     *
     * @param BaseModelInterface $object
     * @param Model $currentUser
     * @param $errorMessages
     * @param string $relation
     * @param array $relationship
     * @param array $relationValues
     * @param array $managedRelationIds
     * @param array $relationsToLoad
     */
    protected function recursivelyManageRelation(BaseModelInterface &$object, Model $currentUser, &$errorMessages,
                                                 $relation = '', $relationship = [], $relationValues = [],
                                                 &$managedRelationIds = [], &$relationsToLoad = [])
    {
        $edit = false;
        $checkCompletion = false;
        $assign = false;

        /** @var Model $relatedObject */
        $relatedObject = null;
        $relationModel = $relationship['model'];

        if (isset($relationValues['id'])) {
            $relatedObject = $object->getRelatedObject($relation, $relationValues['id'], $assign);

            if ($object::isEditableRelationship($object, $relation, $relatedObject, $relationValues)) {
                $edit = true;
            }
        }

        if (!$relatedObject instanceof $relationModel) {
            /**
             * create a new relationObject of class $relationModel
             */
            $relatedObject = new $relationModel([], $currentUser);

            if ($object::isEditableRelationship($object, $relation, $relatedObject, $relationValues)) {
                $edit = true;
                $checkCompletion = true;
                $assign = true;
            } else {
                $relatedObject = null;
            }
        }

        /**
         * update existing relationObject
         * (if model does not define 'editable' => false for the relationship)
         */
        if ($edit && !empty($relationValues)) {
            $relationValues[$relationship['inverse']] = ['id' => $object->id];

            $this->editObjectContents(
                $relatedObject,
                $relationValues,
                $checkCompletion,
                false,
                $relationsToLoad
            );
        }

        if ($relatedObject instanceof $relationModel) {
            if (!in_array($relatedObject->id, $managedRelationIds)) {
                $managedRelationIds[] = $relatedObject->id;
            }

            if ($assign) {
                // save and associate the new/modified relationObject (containing its new/modified relations)
                switch (get_class($object->$relation())) {
                    case HasOne::class:
                        if ($relatedObject->hasRelationship($relationship['inverse'])) {
                            $object->$relation()->save($relatedObject);
                        } else {
                            $msg = Lang::get(
                                'extended_model.errors.missing_relation_configuration',
                                ['relation' => $relationship['inverse'], 'model' => $relationModel]
                            );
                            $errorMessages[$relation][] = $msg;
                        }
                        break;

                    case BelongsTo::class:
                        if ($relatedObject->hasRelationship($relationship['inverse'])) {
                            $object->$relation()->associate($relatedObject);
                        } else {
                            $msg = Lang::get(
                                'extended_model.errors.missing_relation_configuration',
                                ['relation' => $relationship['inverse'], 'model' => $relationModel]
                            );
                            $errorMessages[$relation][] = $msg;
                        }
                        break;

                    case HasMany::class:
                        if ($relatedObject->hasRelationship($relationship['inverse'])) {
                            $object->$relation()->save($relatedObject);

                            // refresh the $object to make sure all related objects are in $relation Collection
                            $object->refresh();
                        } else {
                            $msg = Lang::get(
                                'extended_model.errors.missing_relation_configuration',
                                ['relation' => $relationship['inverse'], 'model' => $relationModel]
                            );
                            $errorMessages[$relation][] = $msg;
                        }
                        break;

                    case BelongsToMany::class:
                        if ($relatedObject->hasRelationship($relationship['inverse'])) {
                            $pivotAttributes = [];
                            if (isset($relationship['pivotable']) && $relationship['pivotable']
                                && isset($relationValues['pivot'])
                            ) {
                                $pivotAttributes = $relationValues['pivot'];
                            }

                            // add the new $relatedObject only once (no duplicated associations)
                            $object->$relation()->syncWithoutDetaching($relatedObject, $pivotAttributes);

                            // refresh the $object to make sure all related objects are in $relation Collection
                            $object->refresh();
                        } else {
                            $msg = Lang::get(
                                'extended_model.errors.missing_relation_configuration',
                                ['relation' => $relationship['inverse'], 'model' => $relationModel]
                            );
                            $errorMessages[$relation][] = $msg;
                        }
                        break;
                }
            }
        }
    }

    /**
     * Fill object's attributes (fillable and guarded) with request parameters
     *
     * @param BaseModelInterface $object
     * @param array $requestParams
     * @param array $validationRules
     */
    protected function setObjectAttributes(BaseModelInterface &$object, $requestParams = [], $validationRules = [])
    {
        $activeValidRules = [];

        if (!empty($validationRules)) {
            foreach ($requestParams as $key => $value) {
                if ($object->$key != $value) {
                    if (isset($validationRules[$key])) {
                        $activeValidRules[$key] = $validationRules[$key];
                    }
                }
            }

            // make a temporary request with only the current $requestParams
            $tmpRequest = new Request();
            $tmpRequest->setMethod(request()->getMethod());
            $tmpRequest->merge($requestParams);
            $tmpRequest->setUserResolver(request()->getUserResolver());

            // validate only the params with the rules
            $this->validate($tmpRequest, $activeValidRules);
        }

        // update all modifiable attributes with sent parameter values
        $attributes = $object->getAllAttributes();

        foreach ($requestParams as $key => $value) {
            if (in_array($key, $attributes) && $value != $object->$key) {
                // explicitly set changed attributes
                $object->$key = $value;
            }
        }
    }

    /**
     * @param BaseModelInterface[] $data
     * @param array $config
     * @param array $mergeData
     * @return mixed
     */
    protected function exportData($data = [], $config = [], $mergeData = [])
    {
        if (!is_array($data)) {
            $data = [$data];
        } else {
            $config['massExport'] = true;
        }

        $exportType = request()->exists('export_type') ? request()->input('export_type') : self::EXPORT_TYPE_PDF;
        $viewType = request()->exists('view_type') ? request()->input('view_type') : self::VIEW_TYPE_DOWNLOAD;
        $fileName = isset($config['file_name']) ? $config['file_name'] : $viewType;
        switch ($exportType) {
            default:
                $fileName .= '.pdf';
                $pdf = $this->generatePdf($data, $config, $mergeData);
                $output = $pdf->output($fileName);

                if ($viewType == self::VIEW_TYPE_VIEW) {
                    return response()->make($output, 200, [
                        'Content-Type' => 'application/pdf',
                        'Content-Disposition' => 'inline; filename="' . $fileName . '"'
                    ]);
                }

                return response()->make($output, 200, [
                    'Content-Description' => 'File Transfer',
                    'Content-Transfer-Encoding' => 'binary',
                    'Cache-Control' => 'public, must-revalidate, max-age=0',
                    'Pragma' => 'public',
                    'Last-Modified' => gmdate('D, d M Y H:i:s') . ' GMT',
                    'Content-Type' => 'application/force-download',
                    'Content-Type' => 'application/octet-stream',
                    'Content-Type' => 'application/download',
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
                    'Content-Length' => strlen($output)
                ]);
                break;
        }
    }

    /**
     * @param BaseModelInterface|BaseModelInterface[] $data
     * @param array $config
     * @param array $mergeData
     * @return static
     */
    public function generatePdf($data, $config = [], $mergeData = [])
    {
        $model = isset($config['model']) ? $config['model'] : 'ExtendedModel';
        $variable = isset($config['variable']) ? $config['variable'] : $model;

        if (isset($config['massExport']) && $config['massExport']) {
            $title = Lang::get($model . '.mass_title');
        } else {
            $title = Lang::get($model . '.title') . ' ' . $data[0]->id;
        }

        // if no specific columns are selected for export, get them all
        $columns = [];
        if (request()->exists('export_column')) {
            $exportColumn = request()->input('export_column');
            if (count($exportColumn) == 1 && !is_array($exportColumn)) {
                // make sure that $columns is an array
                $columns = [$exportColumn];
            } else {
                $columns = $exportColumn;
            }
        }

        if (!isset($config['default_font'])) {
            $config['default_font'] = $this->pdfConfig['default_font'];
        }

        if (!isset($config['default_font_size'])) {
            $config['default_font_size'] = $this->pdfConfig['default_font_size'];
        }

        $pdf = PDF::loadView(
            'pdf.' . $model,
            [$variable => $data, 'title' => $title, 'columns' => $columns],
            $mergeData,
            $config
        );
        return $pdf;
    }
}