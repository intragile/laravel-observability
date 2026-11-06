<?php

declare(strict_types=1);

namespace IntrAgile\Observability;

use Illuminate\Support\ServiceProvider;

final class ObservabilityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/observability.php', 'observability');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/observability.php' => $this->app->configPath('observability.php'),
            ], 'observability-config');
        }
    }
}
