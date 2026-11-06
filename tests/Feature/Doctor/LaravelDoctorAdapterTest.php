<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Tests\Feature\Doctor;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use IntrAgile\Observability\Doctor\LaravelDoctor\ErrorDigestIsDeliverable;
use IntrAgile\Observability\Doctor\LaravelDoctor\HealthChecksPass;
use IntrAgile\Observability\Doctor\LaravelDoctor\LoggingIsStructured;
use IntrAgile\Observability\Doctor\LaravelDoctor\PrometheusIsProtected;
use IntrAgile\Observability\Logging\LokiJsonFormatter;
use IntrAgile\Observability\Tests\TestCase;
use Monolog\Handler\StreamHandler;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;
use Spatie\Health\Health;
use Spatie\Health\ResultStores\ResultStore;
use Spatie\Health\ResultStores\StoredCheckResults\StoredCheckResult;
use Spatie\Health\ResultStores\StoredCheckResults\StoredCheckResults;

final class LaravelDoctorAdapterTest extends TestCase
{
    private const DOCTOR_PROVIDER = 'Laravel\Doctor\DoctorServiceProvider';

    private const PROMETHEUS_PROVIDER = 'Spatie\Prometheus\PrometheusServiceProvider';

    protected function setUp(): void
    {
        if (! class_exists(self::DOCTOR_PROVIDER)) {
            $this->markTestSkipped('laravel/doctor is not installed.');
        }

        if (! class_exists(self::PROMETHEUS_PROVIDER)) {
            $this->markTestSkipped('spatie/laravel-prometheus is not installed.');
        }

        parent::setUp();
    }

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), self::PROMETHEUS_PROVIDER, self::DOCTOR_PROVIDER];
    }

    /**
     * An open metrics endpoint: no basic auth and no IP allow list.
     *
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $config = $app->make('config');
        $config->set('observability.prometheus.basic_auth.enabled', false);
        $config->set('prometheus.allowed_ips', []);
        $config->set('logging.default', 'json');
        $config->set('logging.channels.json', [
            'driver' => 'monolog',
            'handler' => StreamHandler::class,
            'with' => ['stream' => 'php://memory'],
            'formatter' => LokiJsonFormatter::class,
        ]);
    }

    public function test_doctor_lists_four_observability_diagnostics_and_fails_an_open_endpoint(): void
    {
        $exitCode = Artisan::call('doctor', ['--only' => ['observability'], '--format' => 'json']);
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($report);
        $this->assertIsArray($report['diagnostics']);

        $statuses = [];

        foreach ($report['diagnostics'] as $diagnostic) {
            $this->assertIsArray($diagnostic);
            $this->assertSame('observability', $diagnostic['group']);
            $this->assertIsString($diagnostic['class']);
            $statuses[$diagnostic['class']] = $diagnostic['status'];
        }

        $this->assertSame([
            LoggingIsStructured::class,
            PrometheusIsProtected::class,
            HealthChecksPass::class,
            ErrorDigestIsDeliverable::class,
        ], array_keys($statuses));
        $this->assertSame('fail', $statuses[PrometheusIsProtected::class]);
        $this->assertSame('pass', $statuses[LoggingIsStructured::class]);
        $this->assertSame('skip', $statuses[ErrorDigestIsDeliverable::class]);
        $this->assertSame(1, $exitCode);
    }

    public function test_adapter_never_runs_health_checks(): void
    {
        $check = new DoctorAdapterCountingCheck;
        app(Health::class)->checks([$check]);

        Artisan::call('doctor', ['--only' => ['observability'], '--format' => 'json']);

        $this->assertSame(0, $check->runs);
    }

    public function test_health_checks_pass_reports_registration_then_stored_results_then_scheduling(): void
    {
        $check = new DoctorAdapterCountingCheck;
        app(Health::class)->checks([$check]);
        $this->instance(ResultStore::class, new DoctorAdapterOkResultStore);

        Artisan::call('doctor', ['--only' => ['observability'], '--format' => 'json']);
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($report);
        $this->assertIsArray($report['diagnostics']);

        $details = null;

        foreach ($report['diagnostics'] as $diagnostic) {
            $this->assertIsArray($diagnostic);

            if ($diagnostic['class'] === HealthChecksPass::class) {
                $details = $diagnostic['details'] ?? null;
            }
        }

        $this->assertIsString($details);

        $registration = strpos($details, '] Health checks:');
        $stored = strpos($details, '] Stored health results:');
        $scheduling = strpos($details, '] Health check schedule:');

        $this->assertNotFalse($registration);
        $this->assertNotFalse($stored);
        $this->assertNotFalse($scheduling);
        $this->assertLessThan($stored, $registration);
        $this->assertLessThan($scheduling, $stored);
        $this->assertStringContainsString('- [ok] Stored health results: 1 checks ok, last run 0 minutes ago.', $details);
        $this->assertSame(0, $check->runs);
    }
}

final class DoctorAdapterCountingCheck extends Check
{
    public int $runs = 0;

    public function run(): Result
    {
        $this->runs++;

        return Result::make()->ok();
    }
}

final class DoctorAdapterOkResultStore implements ResultStore
{
    public function save(Collection $checkResults): void {}

    public function latestResults(): StoredCheckResults
    {
        return new StoredCheckResults(now(), new Collection([
            new StoredCheckResult(name: 'database', label: 'Database', status: 'ok'),
        ]));
    }
}
