<?php

declare(strict_types=1);

namespace IntrAgile\Observability;

use Closure;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use IntrAgile\Observability\Backup\RecordBackupHeartbeat;
use IntrAgile\Observability\Console\DoctorCommand;
use IntrAgile\Observability\Console\ErrorDigestCommand;
use IntrAgile\Observability\Console\InstallCommand;
use IntrAgile\Observability\Doctor\DoctorRunner;
use IntrAgile\Observability\Doctor\LaravelDoctor\ObservabilityDiagnostic;
use IntrAgile\Observability\Doctor\Probes\BroadcastProbe;
use IntrAgile\Observability\Doctor\Probes\ErrorDigestProbe;
use IntrAgile\Observability\Doctor\Probes\HealthChecksProbe;
use IntrAgile\Observability\Doctor\Probes\LoggingProbe;
use IntrAgile\Observability\Doctor\Probes\PrometheusProbe;
use IntrAgile\Observability\Doctor\Probes\SchedulingProbe;
use IntrAgile\Observability\Health\HealthCheckScheduler;
use IntrAgile\Observability\Http\Controllers\HealthJsonController;
use IntrAgile\Observability\Http\Middleware\PrometheusBasicAuth;
use IntrAgile\Observability\Http\Middleware\RecordRequestTiming;
use IntrAgile\Observability\Metrics\PrometheusRegistrar;
use IntrAgile\Observability\Readiness\Inspector;
use IntrAgile\Observability\Support\ContainerMemory;
use IntrAgile\Observability\Support\RootExecutionGuard;
use Spatie\Health\Checks\Check;
use Spatie\Health\Health;

/**
 * Wires the package into the application.
 *
 * Optional packages (spatie/laravel-prometheus, spatie/laravel-backup, laravel/reverb,
 * laravel/doctor) are only touched after Inspector has confirmed they are installed, and only
 * through class name strings, so the application boots unchanged when any of them is missing.
 */
final class ObservabilityServiceProvider extends ServiceProvider
{
    private const DOCTOR_DIAGNOSTIC_CLASS = 'Laravel\Doctor\Diagnostic';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/observability.php', 'observability');

        $this->app->singleton(Inspector::class, fn (): Inspector => new Inspector($this->config()));

        // Static probes first, then the live ones, so the report reads from configuration to network.
        $this->app->bind(DoctorRunner::class, static fn (Application $app): DoctorRunner => new DoctorRunner(
            $app->make(Inspector::class),
            [
                $app->make(LoggingProbe::class),
                $app->make(SchedulingProbe::class),
                $app->make(PrometheusProbe::class),
                $app->make(ErrorDigestProbe::class),
                $app->make(HealthChecksProbe::class),
                $app->make(BroadcastProbe::class),
            ],
        ));

        // Deferred to the booting phase because the Prometheus provider may merge its own config
        // after this provider registers; appending earlier would replace its default middleware
        // list instead of extending it. Booting callbacks still run before any provider boots,
        // so the route is defined with the final list.
        $this->app->booting(function (): void {
            $this->appendPrometheusBasicAuth();
        });
    }

    public function boot(): void
    {
        $inspector = $this->app->make(Inspector::class);
        $config = $this->config();

        if ($config->get('observability.health.register')) {
            $this->registerHealthChecks($inspector->checks());
        }

        if ($config->get('observability.health.checks.request_timing.enabled')) {
            $this->pushRequestTimingMiddleware();
        }

        if ($inspector->backupInstalled()) {
            Event::listen(Inspector::BACKUP_EVENT_CLASS, RecordBackupHeartbeat::class);
        }

        if ($inspector->prometheusInstalled()) {
            $this->registerPrometheusMetrics();
        }

        if ($config->get('observability.error_digest.enabled')) {
            $this->scheduleErrorDigest();
        }

        if ($config->get('observability.health.schedule.enabled')) {
            $this->scheduleHealthChecks();
        }

        if ($config->get('observability.health.json_endpoint.enabled')) {
            $this->registerHealthJsonRoute();
        }

        if (class_exists(self::DOCTOR_DIAGNOSTIC_CLASS) && class_exists(Inspector::DOCTOR_CLASS)) {
            // Fires immediately when laravel/doctor was already resolved, otherwise on first use.
            $this->callAfterResolving(Inspector::DOCTOR_CLASS, static function (object $doctor): void {
                ObservabilityDiagnostic::register($doctor);
            });
        }

        if ($this->app->runningInConsole()) {
            // Unit tests commonly run as root inside containers; exiting there would kill the runner.
            if (! $this->app->runningUnitTests()) {
                $this->enforceRootGuard(RootExecutionGuard::currentEuid());
            }

            $this->commands([DoctorCommand::class, ErrorDigestCommand::class, InstallCommand::class]);

            $this->publishes([
                __DIR__.'/../config/observability.php' => $this->app->configPath('observability.php'),
            ], 'observability-config');

            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
            ], 'observability-migrations');
        }
    }

    /**
     * Stops the process when Artisan runs as root in a guarded environment.
     *
     * Returns whether the process was blocked; with the default abort callback it never returns
     * in that case. The callback is injectable so the decision can be tested without exiting.
     *
     * @param  (Closure(string): void)|null  $abort
     *
     * @internal
     */
    public function enforceRootGuard(?int $euid, ?Closure $abort = null): bool
    {
        $config = $this->config();

        if (! $config->get('observability.root_guard.enabled')) {
            return false;
        }

        $allowEnv = $config->get('observability.root_guard.allow_env');
        $allowEnv = is_string($allowEnv) && $allowEnv !== '' ? $allowEnv : 'ARTISAN_ALLOW_ROOT';
        $environments = $config->get('observability.root_guard.environments');

        $blocked = RootExecutionGuard::shouldBlock(
            $euid,
            (string) $this->app->environment(),
            is_array($environments) ? $environments : [],
            filter_var(Env::get($allowEnv), FILTER_VALIDATE_BOOLEAN),
        );

        if (! $blocked) {
            return false;
        }

        $abort ??= static function (string $message): never {
            fwrite(STDERR, $message.PHP_EOL);

            exit(1);
        };

        $abort(RootExecutionGuard::message($allowEnv));

        return true;
    }

    private function appendPrometheusBasicAuth(): void
    {
        $config = $this->config();

        if (! $this->app->make(Inspector::class)->prometheusInstalled()
            || ! $config->get('observability.prometheus.basic_auth.enabled')) {
            return;
        }

        $middleware = $config->get('prometheus.middleware');
        $middleware = is_array($middleware) ? $middleware : [];

        // A cached config already contains the entry; never add it twice.
        if (! in_array(PrometheusBasicAuth::class, $middleware, true)) {
            $middleware[] = PrometheusBasicAuth::class;
            $config->set('prometheus.middleware', $middleware);
        }
    }

    /**
     * Registers the checks, skipping any whose name is already taken.
     *
     * spatie/laravel-health throws on duplicate check names, so a second boot (or an application
     * that registered one of these checks itself) must not take the whole application down.
     *
     * @param  list<Check>  $checks
     */
    private function registerHealthChecks(array $checks): void
    {
        $health = $this->app->make(Health::class);
        $taken = $health->registeredChecks()->map(static fn (Check $check): string => $check->getName())->all();

        $new = array_values(array_filter(
            $checks,
            static fn (Check $check): bool => ! in_array($check->getName(), $taken, true),
        ));

        if ($new !== []) {
            $health->checks($new);
        }
    }

    private function pushRequestTimingMiddleware(): void
    {
        $groups = $this->config()->get('observability.health.checks.request_timing.middleware_groups');
        $router = $this->app->make(Router::class);

        foreach (is_array($groups) ? $groups : [] as $group) {
            if (is_string($group) && $group !== '') {
                // pushMiddlewareToGroup() ignores a middleware the group already contains.
                $router->pushMiddlewareToGroup($group, RecordRequestTiming::class);
            }
        }
    }

    /**
     * Metrics are attached when the Prometheus registry is first resolved, so requests that never
     * render metrics do not pay for reading the cgroup files.
     */
    private function registerPrometheusMetrics(): void
    {
        $this->callAfterResolving(Inspector::PROMETHEUS_CLASS, function (): void {
            $config = $this->config();
            $root = $config->get('observability.health.checks.container_memory.root');
            $prometheus = $config->get('observability.prometheus');

            /** @var array<string, mixed> $settings */
            $settings = is_array($prometheus) ? $prometheus : [];

            (new PrometheusRegistrar(new ContainerMemory(is_string($root) && $root !== '' ? $root : '/sys/fs/cgroup')))
                ->register($settings);
        });
    }

    private function scheduleErrorDigest(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $cron = $this->config()->get('observability.error_digest.schedule');

            $schedule->command(ErrorDigestCommand::class)
                ->cron(is_string($cron) && $cron !== '' ? $cron : '*/5 * * * *')
                ->withoutOverlapping()
                ->onOneServer();
        });
    }

    /**
     * The warmup decision is made when the schedule is resolved, which happens once per
     * schedule:run tick, so every tick re-reads the deploy marker.
     */
    private function scheduleHealthChecks(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $config = $this->config();
            $cron = $config->get('observability.health.schedule.cron');

            HealthCheckScheduler::fromConfig($config, fn (string $path): string => $this->app->storagePath($path))
                ->register(
                    $schedule,
                    is_string($cron) && $cron !== '' ? $cron : '* * * * *',
                    (bool) $config->get('observability.health.schedule.on_one_server', true),
                );
        });
    }

    private function registerHealthJsonRoute(): void
    {
        $config = $this->config();
        $uri = $config->get('observability.health.json_endpoint.uri');
        $middleware = $config->get('observability.health.json_endpoint.middleware');
        $name = $config->get('observability.health.json_endpoint.name');

        $route = Route::get(is_string($uri) && $uri !== '' ? $uri : 'health-json', HealthJsonController::class)
            ->middleware(is_array($middleware) ? array_values(array_filter($middleware, is_string(...))) : []);

        if (is_string($name) && $name !== '') {
            $route->name($name);
        }
    }

    private function config(): Repository
    {
        return $this->app->make(Repository::class);
    }
}
