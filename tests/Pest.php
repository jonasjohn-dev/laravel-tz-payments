<?php

use JonasJohn\TzPayments\Tests\TestCase;

// uses() works on every Pest version we support (2 to 5); pest()->extend() needs Pest 3+.
uses(TestCase::class)->in('Feature', 'Unit');
