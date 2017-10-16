<?php

namespace Anexia\BaseModel\Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;

abstract class DbTestCase extends BaseTestCase
{
    use DatabaseTransactions;

    /**
     * Creates the application.
     *
     * @return \Illuminate\Foundation\Application
     */
    public function createApplication()
    {
        putenv('DB_CONNECTION=pgsql_testing');

        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();
        $callResult = null;
        Artisan::call('migrate');
        $output = Artisan::output();

        if (strpos($output, 'Migration table created successfully.') === 0) {
            Artisan::call('db:seed');
        }

        return $app;
    }
}
