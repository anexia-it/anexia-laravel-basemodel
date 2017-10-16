<?php

namespace Anexia\BaseModel\Tests\Unit\Models;

use Anexia\BaseModel\Interfaces\BaseModelInterface;
use Anexia\BaseModel\Interfaces\ExtendedModelInterface;
use App\BaseModel;
use App\User;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Facades\Facade;
use Tests\DbTestCase;

abstract class BaseModelTestCase extends DbTestCase
{
    /** @var string */
    protected $modelClass;
    /** @var BaseModelInterface */
    protected $model;

    protected function setUp()
    {
        parent::setUp();

        $testClass = preg_replace('/Test$/', '', get_class($this));
        $exploded = explode('\\', $testClass);
        $this->modelClass = 'App\\' . $exploded[count($exploded) - 1];

        $this->mockLogin();

        $this->model = new $this->modelClass();
    }

    /**
     * @param int $userId
     * @return mixed|null
     *
     * e.g.:
     * protected function mockLogin($userId = 1)
     * {
     *     // mock the user of request()->user()
     *     $this->be(User::find($userId));
     *     $this->call('GET', 'login');
     * }
     *
     */
    abstract protected function mockLogin($userId = 1);

    /**
     * @param int $id
     * @return Model|null
     *
     * e.g.:
     * public function getUser($id = 1)
     * {
     *     return User::find($id);
     * }
     *
     */
    abstract public function getUser($id = 1);

    /**
     * Make sure each related model has an inverse relation to $this->modelClass
     */
    public function testRelationInverse()
    {
        /** @var array $relations */
        $relations = $this->model->getRelationships();
        if (!empty($relations)) {
            foreach ($relations as $type => $relation) {
                foreach ($relation as $relationName => $config) {
                    // make sure the relationship is properly defined
                    $this->assertArrayHasKey('model', $config);
                    $this->assertArrayHasKey('inverse', $config);

                    // check the related model for the inverse relation
                    /** @var string $relatedClass */
                    $relatedClass = $config['model'];

                    if (!is_subclass_of($relatedClass, Pivot::class)) {
                        $relatedRelations = $relatedClass::getRelationships(true);
                        $inverseRelationName = $config['inverse'];
                        $this->assertArrayHasKey(
                            $inverseRelationName,
                            $relatedRelations,
                            $this->modelClass . ': Related model ' . $relatedClass . ' does not define the relationship ' . $inverseRelationName
                        );
                        $this->assertEquals(
                            $this->modelClass,
                            $relatedRelations[$inverseRelationName],
                            $this->modelClass . ': Related model ' . $relatedClass . ' relation ' . $inverseRelationName . ' works with model ' . $relatedRelations[$inverseRelationName] . ' instead of model ' . $this->modelClass
                        );
                    }
                }
            }
        }
    }

    /**
     * Make sure $this->model is of class $this->modelClass
     * check that default values got set correctly
     */
    public function testDefaultValues()
    {
        $this->assertInstanceOf($this->modelClass, $this->model);

        $defaults = $this->model->getDefaults();

        foreach ($defaults as $attribute => $value) {
            $this->assertEquals($value, $this->model->$attribute, 'Attribute ' . $attribute . ' value ' . $this->model->$attribute . ' does not match expected value ' . $value);
        }
    }
}
