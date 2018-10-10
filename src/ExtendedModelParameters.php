<?php

namespace Anexia\BaseModel;

use Anexia\BaseModel\Interfaces\BaseModelInterface;

class ExtendedModelParameters
{
    private $modelClass;
    private $page = 1;
    private $pagination = 10;
    private $columns = ['*'];
    private $includes = [];
    private $sortings = [];
    private $filters = [];
    private $orFilters = [];
    private $searches = [];
    private $orSearches = [];
    private $notEmptyFilters = [];
    private $complexFilters = [];
    private $decryptionKey;

    /**
     * ExtendedModelParameters constructor.
     * @param BaseModelInterface|null $modelClass
     */
    public function __construct($modelClass = null)
    {
        $this->setModelClass($modelClass);
    }

    /**
     * @return int
     */
    public function getPage()
    {
        return $this->page;
    }

    /**
     * @param int $page
     * @return ExtendedModelParameters
     */
    public function setPage($page)
    {
        $this->page = $page;
        return $this;
    }

    /**
     * @return int
     */
    public function getPagination()
    {
        return $this->pagination;
    }

    /**
     * @param int $pagination
     * @return ExtendedModelParameters
     */
    public function setPagination($pagination)
    {
        $this->pagination = $pagination;
        return $this;
    }

    /**
     * @return array
     */
    public function getIncludes()
    {
        return $this->includes;
    }

    /**
     * @param array $includes
     * @return ExtendedModelParameters
     */
    public function setIncludes(array $includes)
    {
        $this->includes = $includes;
        return $this;
    }

    /**
     * @return array
     */
    public function getSortings()
    {
        return $this->sortings;
    }

    /**
     * @param array $sortings
     * @return ExtendedModelParameters
     */
    public function setSortings(array $sortings)
    {
        $this->sortings = $sortings;
        return $this;
    }

    /**
     * @return array
     */
    public function getFilters()
    {
        return $this->filters;
    }

    /**
     * @param array $filters
     * @return ExtendedModelParameters
     */
    public function setFilters(array $filters)
    {
        $this->filters = $filters;
        return $this;
    }

    /**
     * @return array
     */
    public function getOrFilters()
    {
        return $this->orFilters;
    }

    /**
     * @param array $orFilters
     * @return ExtendedModelParameters
     */
    public function setOrFilters(array $orFilters)
    {
        $this->orFilters = $orFilters;
        return $this;
    }

    /**
     * @return array
     */
    public function getSearches()
    {
        return $this->searches;
    }

    /**
     * @param array $searches
     * @return ExtendedModelParameters
     */
    public function setSearches(array $searches)
    {
        $this->searches = $searches;
        return $this;
    }

    /**
     * @return array
     */
    public function getOrSearches()
    {
        return $this->orSearches;
    }

    /**
     * @param array $orSearches
     * @return ExtendedModelParameters
     */
    public function setOrSearches(array $orSearches)
    {
        $this->orSearches = $orSearches;
        return $this;
    }

    /**
     * @return array
     */
    public function getNotEmptyFilters()
    {
        return $this->notEmptyFilters;
    }

    /**
     * @param array $notEmptyFilters
     * @return ExtendedModelParameters
     */
    public function setNotEmptyFilters(array $notEmptyFilters)
    {
        $this->notEmptyFilters = $notEmptyFilters;
        return $this;
    }

    /**
     * @return array
     */
    public function getComplexFilters()
    {
        return $this->complexFilters;
    }

    /**
     * @param array $complexFilters
     * @return ExtendedModelParameters
     */
    public function setComplexFilters(array $complexFilters)
    {
        $this->complexFilters = $complexFilters;
        return $this;
    }

    /**
     * @return BaseModelInterface
     */
    public function getModelClass()
    {
        return $this->modelClass;
    }

    /**
     * @param BaseModelInterface $modelClass
     * @return ExtendedModelParameters
     */
    public function setModelClass($modelClass)
    {
        $this->modelClass = $modelClass;
        return $this;
    }

    /**
     * @return array
     */
    public function getColumns()
    {
        return $this->columns;
    }

    /**
     * @param array $columns
     * @return ExtendedModelParameters
     */
    public function setColumns(array $columns)
    {
        $this->columns = $columns;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getDecryptionKey()
    {
        return $this->decryptionKey;
    }

    /**
     * @param string|null $decryptionKey
     * @return ExtendedModelParameters
     */
    public function setDecryptionKey($decryptionKey)
    {
        $this->decryptionKey = $decryptionKey;
        return $this;
    }
}
