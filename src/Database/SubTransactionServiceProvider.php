<?php

namespace Anexia\BaseModel\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class SubTransactionServiceProvider extends ServiceProvider
{
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