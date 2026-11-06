<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Tests\Feature;

use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ServiceProvider;
use IntrAgile\Observability\Checks\BackupFreshnessCheck;
use IntrAgile\Observability\Checks\BroadcastCheck;
use IntrAgile\Observability\Checks\FailedJobsCheck;
use IntrAgile\Observability\Checks\MailRelayCheck;
use IntrAgile\Observability\Checks\RequestTimingCheck;
use IntrAgile\Observability\Checks\SchemaCompleteCheck;
use IntrAgile\Observability\Http\Middleware\PrometheusBasicAuth;
use IntrAgile\Observability\Http\Middleware\RecordRequestTiming;
use IntrAgile\Observability\ObservabilityServiceProvider;
use IntrAgile\Observability\Readiness\Inspector;
use IntrAgile\Observability\Tests\TestCase;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Spatie\Backup\Events\BackupWasSuccessful;
use Spatie\Health\Checks\Check;
use Spatie\Health\Health;
use Spatie\Prometheus\PrometheusServiceProvider;

final class WiringTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        $providers = parent::getPackageProviders($app);

        // Listed after the package provider on purpose: the middleware must still end up last
        // even though the Prometheus config is merged later than the package registers.
        if (class_exists(PrometheusServiceProvider::class)) {
            $providers[] = PrometheusServiceProvider::class;
        }

        return $providers;
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app->make('config')->set('cache.default', 'array');
    }

    public function test_expected_checks_are_registered(): void
    {
        $classes = $this->registeredCheckClasses();

        foreach ([SchemaCompleteCheck::class, FailedJobsCheck::class, MailRelayCheck::class, RequestTimingCheck::class] as $expected) {
            $this->assertContains($expected, $classes);
        }

        if (class_exists(Inspector::BACKUP_EVENT_CLASS)) {
            $this->assertContains(BackupFreshnessCheck::class, $classes);
        } else {
            $this->assertNotContains(BackupFreshnessCheck::class, $classes);
        }

        if (class_exists(Inspector::REVERB_CLASS) && class_exists(Inspector::WEBSOCKET_CLIENT_CLASS)) {
            $this->assertContains(BroadcastCheck::class, $classes);
        } else {
            $this->assertNotContains(BroadcastCheck::class, $classes);
        }
    }

    public function test_booting_twice_does_not_duplicate_checks(): void
    {
        $before = $this->registeredCheckClasses();

        $application = $this->app;
        $this->assertNotNull($application);
        (new ObservabilityServiceProvider($application))->boot();

        $this->assertSame($before, $this->registeredCheckClasses());
    }

    #[DefineEnvironment('disableHealthRegistration')]
    public function test_no_checks_are_registered_when_registration_is_disabled(): void
    {
        $this->assertSame([], $this->registeredCheckClasses());
    }

    public function test_prometheus_basic_auth_is_appended_once_and_last(): void
    {
        $this->requirePrometheus();

        $middleware = config('prometheus.middleware');
        $this->assertIsArray($middleware);
        $this->assertSame(PrometheusBasicAuth::class, end($middleware));
        $this->assertCount(1, array_keys($middleware, PrometheusBasicAuth::class, true));
        $this->assertGreaterThan(1, count($middleware), 'The default Prometheus middleware must be kept.');

        $route = $this->router()->getRoutes()->getByName('prometheus.default');
        $this->assertInstanceOf(Route::class, $route);
        $routeMiddleware = $route->middleware();
        $this->assertSame(PrometheusBasicAuth::class, end($routeMiddleware));
    }

    #[DefineEnvironment('disableBasicAuth')]
    public function test_prometheus_basic_auth_is_not_appended_when_disabled(): void
    {
        $this->requirePrometheus();

        $middleware = config('prometheus.middleware');
        $this->assertIsArray($middleware);
        $this->assertNotContains(PrometheusBasicAuth::class, $middleware);
    }

    public function test_request_timing_middleware_is_pushed_to_configured_groups(): void
    {
        $groups = $this->router()->getMiddlewareGroups();

        foreach (['web', 'api'] as $group) {
            $this->assertArrayHasKey($group, $groups);
            $this->assertIsArray($groups[$group]);
            $this->assertCount(1, array_keys($groups[$group], RecordRequestTiming::class, true));
        }
    }

    public function test_backup_event_writes_heartbeat(): void
    {
        if (! class_exists(BackupWasSuccessful::class)) {
            $this->markTestSkipped('spatie/laravel-backup is not installed.');
        }

        $this->freezeTime();

        event(new BackupWasSuccessful('local', 'obs-test'));

        $this->assertSame(now()->getTimestamp(), Cache::get(BackupFreshnessCheck::CACHE_KEY));
    }

    #[DefineEnvironment('enableDigest')]
    public function test_digest_is_scheduled(): void
    {
        $events = $this->digestEvents();

        $this->assertCount(1, $events);
        $this->assertSame('*/10 * * * *', $events[0]->expression);
        $this->assertTrue($events[0]->withoutOverlapping);
        $this->assertTrue($events[0]->onOneServer);
    }

    public function test_digest_is_not_scheduled_when_disabled(): void
    {
        $this->assertSame([], $this->digestEvents());
    }

    public function test_commands_are_registered(): void
    {
        $commands = Artisan::all();

        $this->assertArrayHasKey('observability:error-digest', $commands);
        $this->assertArrayHasKey('observability:install', $commands);
    }

    public function test_publish_tags_are_registered(): void
    {
        $config = ServiceProvider::pathsToPublish(ObservabilityServiceProvider::class, 'observability-config');
        $migrations = ServiceProvider::pathsToPublish(ObservabilityServiceProvider::class, 'observability-migrations');

        $this->assertCount(1, $config);
        $this->assertCount(1, $migrations);

        foreach (array_keys($migrations) as $source) {
            $this->assertDirectoryExists($source);
        }
    }

    public function test_root_guard_aborts_for_root_in_a_guarded_environment(): void
    {
        $this->setEnvironment('production');
        $messages = [];

        $blocked = $this->provider()->enforceRootGuard(0, static function (string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $this->assertTrue($blocked);
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('ARTISAN_ALLOW_ROOT=1', $messages[0]);
    }

    public function test_root_guard_lets_other_users_and_environments_through(): void
    {
        $abort = function (string $message): void {
            $this->fail('The guard must not abort: '.$message);
        };

        $this->setEnvironment('production');
        $this->assertFalse($this->provider()->enforceRootGuard(1000, $abort));
        $this->assertFalse($this->provider()->enforceRootGuard(null, $abort));

        $this->setEnvironment('staging');
        $this->assertFalse($this->provider()->enforceRootGuard(0, $abort));

        $this->setEnvironment('production');
        config()->set('observability.root_guard.enabled', false);
        $this->assertFalse($this->provider()->enforceRootGuard(0, $abort));
    }

    public function test_root_guard_honours_the_override_variable(): void
    {
        $this->setEnvironment('production');
        config()->set('observability.root_guard.allow_env', 'OBS_TEST_ALLOW_ROOT');
        putenv('OBS_TEST_ALLOW_ROOT=1');

        try {
            $this->assertFalse($this->provider()->enforceRootGuard(0, function (string $message): void {
                $this->fail('The guard must not abort: '.$message);
            }));
        } finally {
            putenv('OBS_TEST_ALLOW_ROOT');
        }
    }

    /**
     * @param  Application  $app
     */
    protected function disableHealthRegistration($app): void
    {
        $app->make('config')->set('observability.health.register', false);
    }

    /**
     * @param  Application  $app
     */
    protected function disableBasicAuth($app): void
    {
        $app->make('config')->set('observability.prometheus.basic_auth.enabled', false);
    }

    /**
     * @param  Application  $app
     */
    protected function enableDigest($app): void
    {
        $app->make('config')->set('observability.error_digest.enabled', true);
        $app->make('config')->set('observability.error_digest.schedule', '*/10 * * * *');
    }

    /**
     * @return list<class-string<Check>>
     */
    private function registeredCheckClasses(): array
    {
        $health = $this->app?->make(Health::class);
        $this->assertInstanceOf(Health::class, $health);

        return array_values($health->registeredChecks()->map(static fn (Check $check): string => $check::class)->all());
    }

    /**
     * @return list<ScheduledEvent>
     */
    private function digestEvents(): array
    {
        $schedule = $this->app?->make(Schedule::class);
        $this->assertInstanceOf(Schedule::class, $schedule);

        return array_values(array_filter(
            $schedule->events(),
            static fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'observability:error-digest'),
        ));
    }

    private function router(): Router
    {
        $router = $this->app?->make(Router::class);
        $this->assertInstanceOf(Router::class, $router);

        return $router;
    }

    private function provider(): ObservabilityServiceProvider
    {
        $provider = $this->app?->getProvider(ObservabilityServiceProvider::class);
        $this->assertInstanceOf(ObservabilityServiceProvider::class, $provider);

        return $provider;
    }

    private function setEnvironment(string $environment): void
    {
        $this->app?->instance('env', $environment);
    }

    private function requirePrometheus(): void
    {
        if (! class_exists(PrometheusServiceProvider::class)) {
            $this->markTestSkipped('spatie/laravel-prometheus is not installed.');
        }
    }
}
