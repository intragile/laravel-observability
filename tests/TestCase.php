<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Tests;

use IntrAgile\Observability\ObservabilityServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\Health\HealthServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [HealthServiceProvider::class, ObservabilityServiceProvider::class];
    }
}
