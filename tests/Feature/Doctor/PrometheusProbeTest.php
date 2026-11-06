<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Tests\Feature\Doctor;

use Illuminate\Contracts\Foundation\Application;
use IntrAgile\Observability\Doctor\DoctorOptions;
use IntrAgile\Observability\Doctor\Probes\PrometheusProbe;
use IntrAgile\Observability\Readiness\Inspector;
use IntrAgile\Observability\Readiness\ReadinessEntry;
use IntrAgile\Observability\Readiness\ReadinessStatus;
use IntrAgile\Observability\Tests\TestCase;
use Orchestra\Testbench\Attributes\DefineEnvironment;

final class PrometheusProbeTest extends TestCase
{
    private const PROMETHEUS_PROVIDER = 'Spatie\Prometheus\PrometheusServiceProvider';

    private string $tempDir;

    protected function setUp(): void
    {
        // A cgroup v2 layout in a unique directory stands in for a memory-limited container.
        $this->tempDir = sys_get_temp_dir().'/obs-doctor-'.bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0700, true);
        file_put_contents($this->tempDir.'/memory.max', "1073741824\n");
        file_put_contents($this->tempDir.'/memory.stat', "anon 268435456\nfile 1024\n");

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach (['memory.max', 'memory.stat'] as $file) {
            @unlink($this->tempDir.'/'.$file);
        }

        @rmdir($this->tempDir);
    }

    protected function getPackageProviders($app): array
    {
        $providers = parent::getPackageProviders($app);

        if (class_exists(self::PROMETHEUS_PROVIDER)) {
            $providers[] = self::PROMETHEUS_PROVIDER;
        }

        return $providers;
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $config = $app->make('config');
        $config->set('observability.health.checks.container_memory.root', $this->tempDir);
        $config->set('observability.prometheus.basic_auth.password', 's3cret');
    }

    public function test_protected_endpoint_is_ok(): void
    {
        $this->requirePrometheus();

        $entries = $this->probe();

        $this->assertCount(3, $entries);

        foreach ($entries as $entry) {
            $this->assertSame(ReadinessStatus::Ok, $entry->status, $entry->component.': '.$entry->message);
        }

        $this->assertStringContainsString('401', $entries[0]->message);
        $this->assertStringContainsString('200', $entries[1]->message);
        $this->assertStringContainsString('container_memory_limit_bytes', $entries[2]->message);
    }

    #[DefineEnvironment('openEndpoint')]
    public function test_open_metrics_endpoint_fails(): void
    {
        $this->requirePrometheus();

        $entries = $this->probe();

        $this->assertSame(ReadinessStatus::Failed, $entries[0]->status);
        $this->assertStringContainsString('metrics endpoint is open', $entries[0]->message);
    }

    #[DefineEnvironment('ipAllowListOnly')]
    public function test_ip_allow_list_counts_as_protection(): void
    {
        $this->requirePrometheus();

        $entries = $this->probe();

        $this->assertSame(ReadinessStatus::Ok, $entries[0]->status, $entries[0]->message);
        $this->assertSame(ReadinessStatus::Ok, $entries[1]->status, $entries[1]->message);
    }

    public function test_missing_prometheus_package_is_skipped(): void
    {
        $inspector = new Inspector(config(), static fn (string $class): bool => false);

        $entries = (new PrometheusProbe($inspector, app(), config()))->run($this->doctorOptions());

        $this->assertCount(1, $entries);
        $this->assertSame(ReadinessStatus::Skipped, $entries[0]->status);
        $this->assertStringContainsString('spatie/laravel-prometheus', $entries[0]->message);
    }

    /**
     * @return list<ReadinessEntry>
     */
    private function probe(): array
    {
        return app(PrometheusProbe::class)->run($this->doctorOptions());
    }

    private function doctorOptions(): DoctorOptions
    {
        return new DoctorOptions(live: false, sendTest: false, timeout: 5.0);
    }

    /**
     * Route middleware is fixed when the route is registered, so these settings are applied
     * before the application boots instead of being changed afterwards.
     *
     * @param  Application  $app
     */
    protected function openEndpoint($app): void
    {
        $app->make('config')->set('observability.prometheus.basic_auth.enabled', false);
        $app->make('config')->set('prometheus.allowed_ips', []);
    }

    /**
     * @param  Application  $app
     */
    protected function ipAllowListOnly($app): void
    {
        $app->make('config')->set('observability.prometheus.basic_auth.enabled', false);
        $app->make('config')->set('prometheus.allowed_ips', ['10.0.0.0/8']);
    }

    private function requirePrometheus(): void
    {
        if (! class_exists(self::PROMETHEUS_PROVIDER)) {
            $this->markTestSkipped('spatie/laravel-prometheus is not installed.');
        }
    }
}
