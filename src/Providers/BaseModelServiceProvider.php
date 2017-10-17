<?php
namespace Anexia\BaseModel\Providers;

use Anexia\BaseModel\Database\PostgresConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

/**
 * Class BaseModelServiceProvider
 * @package Anexia\BaseModel\Providers
 */
class BaseModelServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        // nothing happening here
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        // register the custom PostgresConnection (that uses the custom Connection which supports nested transactions)
        // as default pgsql connection
        DB::resolverFor('pgsql', function ($connection, $database, $prefix, $config) {
            return new PostgresConnection($connection, $database, $prefix, $config);
        });
    }
}