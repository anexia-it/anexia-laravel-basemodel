<?php

namespace Anexia\BaseModel\Controllers;

use Anexia\BaseModel\Interfaces\BaseModelInterface;
use Anexia\BaseModel\Database\Connection;
use Anexia\BaseModel\Exceptions\Query\SqlException;
use Anexia\BaseModel\Exceptions\Validation\BulkValidationException;
use Anexia\BaseModel\Traits\DecryptionKeyFromAccessToken;
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
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests, DecryptionKeyFromAccessToken;

    /** string */
    const EXPORT_TYPE_PDF = 'pdf';
    /** string */
    const EXPORT_TYPE_CSV = 'csv';

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
     * @param array $callingParentRelationship
     * @throws BulkValidationException
     */
    protected function editObjectContents(BaseModelInterface &$object, $requestParams = [], $checkCompletion = true,
                                          $manageTransaction = true, &$relationsToLoad = [],
                                          $callingParentRelationship = [])
    {
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
            $this->manageRequiredRelations(
                $object,
                $requestParams,
                $errorMessages,
                $relationsToLoad,
                $callingParentRelationship
            );

            /**
             * save the object (it has an id from here on out, necessary for the optional relations)
             */
            $object->save();

            /**
             * recursively add/fill/remove all optional (not required) relations
             */
            $this->manageOptionalRelations(
                $object,
                $requestParams,
                $errorMessages,
                $relationsToLoad,
                $callingParentRelationship
            );

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
     * @param array $callingParentRelationship
     */
    private function manageRequiredRelations(BaseModelInterface &$object, $requestParams = [], &$errorMessages,
                                             &$relationsToLoad, $callingParentRelationship = [])
    {
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
            $ignoreParam = false;
            // if parameter ends with _id convert it to relation and add the id to $values
            $origLength = strlen($relation);
            $relation = preg_replace('/\_id$/','',$relation);
            if (strlen($relation) !== $origLength) {
                // if a relation is given as both 'relation' and 'relation_id', ignore the 'relation_id' part
                if (isset($requestParams[$relation])) {
                    $ignoreParam = true;
                }
                if ($values > 0) {
                    $values = ['id' => $values];
                } else {
                    $values = [];
                }
            }

            if (!$ignoreParam) {
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
                    $childRelations = [];
                    $this->recursivelyManageRelation(
                        $object,
                        $errorMessages,
                        $relation,
                        $relationships['one'][$relation],
                        $values,
                        $managedRelationIds,
                        $childRelations
                    );

                    if (!isset($callingParentRelationship['inverse'])
                        || $callingParentRelationship['inverse'] != $relation
                    ) {
                        $relationsToLoad[$relation] = $childRelations;
                    }
                }
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
     * @param array $callingParentRelationship
     */
    private function manageOptionalRelations(BaseModelInterface &$object, $requestParams = [], &$errorMessages,
                                             &$relationsToLoad, $callingParentRelationship = [])
    {
        /** @var array $relationships - all possible relations of the $object */
        $relationships = $object::getAllRelationships();

        /**
         * remove _id params if their corresponding relationship is given
         */
        $prettyParams = [];
        foreach ($requestParams as $relation => $values) {
            $ignoreParam = false;
            // if parameter ends with _id convert it to relation and add the id to $values
            $origLength = strlen($relation);
            $relation = preg_replace('/\_id$/','',$relation);
            if (strlen($relation) !== $origLength) {
                // if a relation is given as both 'relation' and 'relation_id', ignore the 'relation_id' part
                if (isset($requestParams[$relation])) {
                    $ignoreParam = true;
                }
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

            if (!$ignoreParam) {
                if (isset($prettyParams[$relation]) && is_array($values)) {
                    foreach ($values as $k => $v) {
                        $prettyParams[$relation][$k] = $v;
                    }
                } else {
                    $prettyParams[$relation] = $values;
                }
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
                $childRelations = [];
                // if relation from request is empty, remove the related model from the object
                if (empty($values)) {
                    $object->clearRelation($relation);
                } else {
                    $this->recursivelyManageRelation(
                        $object,
                        $errorMessages,
                        $relation,
                        $relationships['one'][$relation],
                        $values,
                        $managedRelationIds,
                        $childRelations
                    );

                    if (!isset($callingParentRelationship['inverse'])
                        || $callingParentRelationship['inverse'] != $relation
                    ) {
                        $relationsToLoad[$relation] = $childRelations;
                    }
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
                    $childRelations = [];
                    if (!is_int(array_keys($values)[0])) {
                        // only one to many values set given
                        $this->recursivelyManageRelation(
                            $object,
                            $errorMessages,
                            $relation,
                            $relationships['many'][$relation],
                            $values,
                            $managedRelationIds,
                            $childRelations
                        );
                    } else {
                        // manage all related objects at once
                        foreach ($values as $singleRelationValues) {
                            $this->recursivelyManageRelation(
                                $object,
                                $errorMessages,
                                $relation,
                                $relationships['many'][$relation],
                                $singleRelationValues,
                                $managedRelationIds,
                                $childRelations
                            );
                        }
                    }

                    if (!isset($callingParentRelationship['inverse'])
                        || $callingParentRelationship['inverse'] != $relation
                    ) {
                        $relationsToLoad[$relation] = $childRelations;
                    }

                    /**
                     * delete all related objects from the relation that were not part of the request
                     */
                    if ($object->$relation->count() > 0
                        && (!isset($callingParentRelationship['inverse'])
                            || $callingParentRelationship['inverse'] != $relation)
                    ) {
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
     * @param $errorMessages
     * @param string $relation
     * @param array $relationship
     * @param array $relationValues
     * @param array $managedRelationIds
     * @param array $relationsToLoad
     */
    protected function recursivelyManageRelation(BaseModelInterface &$object, &$errorMessages, $relation = '',
                                                 $relationship = [], $relationValues = [], &$managedRelationIds = [],
                                                 &$relationsToLoad = [])
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
            $relatedObject = new $relationModel([]);

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
                $relationsToLoad,
                $relationship
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
                            $object->$relation()->sync([$relatedObject->id => $pivotAttributes], false);

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
            if (count(request()->headers)) {
                $tmpRequest->headers->add((array) request()->headers);
            }

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
        switch (strtolower($exportType)) {
            case self::EXPORT_TYPE_CSV:
                $fileName .= '.csv';
                /** @var string $output */
                $output = $this->generateCsv($data, $config);

                if ($viewType == self::VIEW_TYPE_VIEW) {
                    return response()->make($output, 200, [
                        'Content-Type' => 'text/csv',
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
                    'Content-Type' => 'text/csv',
                    'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
                    'Content-Length' => strlen($output)
                ]);
                break;
            case self::EXPORT_TYPE_PDF:
                // same behaviour as default
            default:
                $fileName .= '.pdf';
                /** @var \niklasravnsborg\LaravelPdf\Pdf $pdf */
                $pdf = $this->generatePdf($data, $config, $mergeData);
                /** @var string $output */
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
                $columns = explode(',', $exportColumn);
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

    /**
     * @param BaseModelInterface|BaseModelInterface[] $data
     * @param array $config
     * @return string
     */
    public function generateCsv($data, $config = [])
    {
        $csvString = '';
        $model = isset($config['model']) ? $config['model'] : 'ExtendedModel';
        $modelClass = isset($config['model_class']) ? $config['model_class'] : 'ExtendedModel';

        // if no specific columns are selected for export, get them all
        $columns = $modelClass::getDefaultExport();
        if (request()->exists('export_column')) {
            $exportColumn = request()->input('export_column', []);
            if (count($exportColumn) == 1 && !is_array($exportColumn)) {
                // make sure that $columns is an array
                $columns = explode(',', $exportColumn);
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

        if (!empty($columns)) {
            $delimeter = isset($config['delimeter']) ? $config['delimeter'] : ',';

            $headers = [];
            $rows = [];
            foreach ($data as $object) {
                $rowData = [];
                foreach ($columns as $name => $column) {
                    if (is_int($name)) {
                        if (strpos($column, '.') > (-1)) {
                            $exploded = explode('.', $column);
                            $relation = $exploded[0];
                            $name = $relation;
                            $value = $this->extractObjectAttribute($object, $column);
                        } else {
                            $name = $column;
                            $value = $object->$column;
                        }
                    } else {
                        $value = '';
                        $parts = explode(' ', $column);
                        foreach ($parts as $part) {
                            $value .= $this->extractObjectAttribute($object, $part) . ' ';
                        }

                        $value = rtrim($value);
                    }

                    if (!isset($headers[$name])) {
                        $headers[$name] = Lang::get($model . '.' . $name);
                    }
                    $rowData[$name] = $value;
                }

                $rows[] = implode($delimeter, $rowData);
            }
            $csvString .= implode($delimeter, $headers) . "\n";
            $csvString .= implode("\n", $rows);
        }

        return $csvString;
    }

    /**
     * @param $object
     * @param string $attribute
     * @return null|string
     */
    private function extractObjectAttribute($object, $attribute)
    {
        if (isset($object->$attribute)) {
            return $object->$attribute;
        } else if (strpos($attribute, '.') > (-1)) {
            $exploded = explode('.', $attribute);
            $relation = $exploded[0];
            unset($exploded[0]);
            $attribute = implode('.', $exploded);
            if ($object->$relation) {
                return $this->extractObjectAttribute($object->$relation, $attribute);
            }
        }

        return null;
    }
}