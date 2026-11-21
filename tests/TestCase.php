<?php

namespace JonasJohn\TzPayments\Tests;

use JonasJohn\TzPayments\Support\Reference;
use JonasJohn\TzPayments\TzPaymentsServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [TzPaymentsServiceProvider::class];
    }

    protected function defineDatabaseMigrations(): void
    {
        (include __DIR__.'/../database/migrations/create_tz_payments_table.php.stub')->up();
    }

    protected function tearDown(): void
    {
        Reference::generateUsing(null);

        parent::tearDown();
    }
}
