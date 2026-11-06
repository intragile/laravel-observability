<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Tests\Feature\Http;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use IntrAgile\Observability\Http\Controllers\HealthJsonController;
use IntrAgile\Observability\Tests\TestCase;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;
use Spatie\Health\Commands\RunHealthChecksCommand;
use Spatie\Health\Facades\Health;
use Spatie\Health\Http\Controllers\HealthCheckJsonResultsController;
use Spatie\Health\Notifications\CheckFailedNotification;
use Spatie\Health\ResultStores\InMemoryHealthResultStore;
use Spatie\Health\ResultStores\ResultStore;

final class HealthJsonControllerTest extends TestCase
{
    /**
     * @param  Router  $router
     */
    protected function defineRoutes($router): void
    {
        $router->get('health-json', HealthJsonController::class);
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $config = $app->make(Repository::class);
        $config->set('cache.default', 'array');
        $config->set('health.result_stores', [InMemoryHealthResultStore::class => []]);
        $config->set('health.notifications.enabled', true);
        $config->set('health.notifications.throttle_notifications_for_minutes', 0);
        $config->set('health.oh_dear_endpoint.always_send_fresh_results', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetInMemoryStore();

        Health::clearChecks();
        Health::checks([new AlwaysFailingCheck]);
    }

    protected function tearDown(): void
    {
        $this->resetInMemoryStore();

        parent::tearDown();
    }

    public function test_fresh_runs_checks_without_sending_notifications(): void
    {
        Notification::fake();

        $response = $this->get('health-json?fresh')->assertOk();

        $response->assertJsonPath('checkResults.0.name', 'AlwaysFailing');
        $response->assertJsonPath('checkResults.0.status', 'failed');
        Notification::assertNothingSent();
    }

    public function test_command_without_flag_would_notify(): void
    {
        Notification::fake();

        Artisan::call(RunHealthChecksCommand::class);

        Notification::assertSentTimes(CheckFailedNotification::class, 1);
    }

    public function test_always_send_fresh_results_config_triggers_fresh_run_without_notifications(): void
    {
        config()->set('health.oh_dear_endpoint.always_send_fresh_results', true);
        Notification::fake();

        $this->get('health-json')
            ->assertOk()
            ->assertJsonPath('checkResults.0.status', 'failed');

        Notification::assertNothingSent();
    }

    public function test_without_fresh_returns_stored_results(): void
    {
        (new InMemoryHealthResultStore)->save(collect([
            Result::make()->ok('Stored')->check(new AlwaysFailingCheck),
        ]));
        Notification::fake();

        $this->get('health-json')
            ->assertOk()
            ->assertJsonPath('checkResults.0.status', 'ok');

        Notification::assertNothingSent();
    }

    public function test_empty_store_returns_empty_body_with_ok_status(): void
    {
        $this->get('health-json')
            ->assertOk()
            ->assertContent('');
    }

    public function test_failure_status_config_is_honoured(): void
    {
        config()->set('health.json_results_failure_status', 503);
        Notification::fake();

        $this->get('health-json?fresh')->assertStatus(503);
    }

    public function test_sets_spatie_cache_control_and_content_type_headers(): void
    {
        $response = $this->get('health-json')
            ->assertHeader('Content-Type', 'application/json');

        $spatie = (new HealthCheckJsonResultsController)(
            Request::create('health-json'),
            app(ResultStore::class),
        );

        $response->assertHeader('Cache-Control', (string) $spatie->headers->get('Cache-Control'));
    }

    private function resetInMemoryStore(): void
    {
        $reset = Closure::bind(
            static function (): void {
                InMemoryHealthResultStore::$storedCheckResults = null;
            },
            null,
            InMemoryHealthResultStore::class,
        );
        $reset();
    }
}

/**
 * A check that always fails with a non-empty message so notification filtering
 * cannot hide whether a notification would have been sent.
 */
final class AlwaysFailingCheck extends Check
{
    public function getName(): string
    {
        return 'AlwaysFailing';
    }

    public function run(): Result
    {
        return Result::make()->failed('Always failing');
    }
}
