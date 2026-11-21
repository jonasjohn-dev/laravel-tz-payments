<?php

namespace JonasJohn\TzPayments\Tests;

use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        // Registered once the service provider exists:
        // return [\JonasJohn\TzPayments\TzPaymentsServiceProvider::class];
        return [];
    }
}
