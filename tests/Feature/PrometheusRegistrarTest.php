<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Tests\Feature;

use IntrAgile\Observability\Metrics\PrometheusRegistrar;
use IntrAgile\Observability\Support\ContainerMemory;
use IntrAgile\Observability\Tests\TestCase;
use Spatie\Prometheus\Prometheus;
use Spatie\Prometheus\PrometheusServiceProvider;

final class PrometheusRegistrarTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        if (! class_exists(Prometheus::class)) {
            $this->markTestSkipped('spatie/laravel-prometheus is not installed.');
        }

        parent::setUp();

        $this->tempDir = sys_get_temp_dir().'/obs-'.bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0700, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), PrometheusServiceProvider::class];
    }

    public function test_exposes_container_memory_gauges_when_limit_exists(): void
    {
        $this->writeCgroup('1073741824', 268435456);

        $this->registrar()->register($this->config(gauges: true));

        $this->get('/prometheus')
            ->assertOk()
            ->assertSee('container_memory_limit_bytes 1073741824', false)
            ->assertSee('container_memory_used_bytes 268435456', false);
    }

    public function test_omits_container_memory_gauges_without_limit(): void
    {
        $this->writeCgroup('max', 268435456);

        $this->registrar()->register($this->config(gauges: true));

        $this->get('/prometheus')
            ->assertOk()
            ->assertDontSee('container_memory_limit_bytes', false)
            ->assertDontSee('container_memory_used_bytes', false);
    }

    public function test_omits_container_memory_gauges_when_disabled(): void
    {
        $this->writeCgroup('1073741824', 268435456);

        $this->registrar()->register($this->config(gauges: false));

        $this->get('/prometheus')
            ->assertOk()
            ->assertDontSee('container_memory_limit_bytes', false)
            ->assertDontSee('container_memory_used_bytes', false);
    }

    public function test_registers_queue_collectors_when_enabled(): void
    {
        $this->registrar()->register($this->config(gauges: false, queues: true));

        $this->get('/prometheus')
            ->assertOk()
            ->assertSee('queue_size', false);
    }

    public function test_does_not_register_queue_collectors_by_default(): void
    {
        $this->registrar()->register($this->config(gauges: false));

        $this->get('/prometheus')
            ->assertOk()
            ->assertDontSee('queue_size', false);
    }

    private function registrar(): PrometheusRegistrar
    {
        return new PrometheusRegistrar(new ContainerMemory($this->tempDir));
    }

    /**
     * @return array<string, mixed>
     */
    private function config(bool $gauges, bool $queues = false): array
    {
        return [
            'container_memory_gauges' => $gauges,
            'queue_collectors' => ['enabled' => $queues, 'connection' => null, 'queues' => ['default']],
        ];
    }

    private function writeCgroup(string $limit, int $anon): void
    {
        file_put_contents($this->tempDir.'/memory.max', $limit."\n");
        file_put_contents($this->tempDir.'/memory.stat', "anon {$anon}\nfile 1024\n");
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;
            is_dir($path) && ! is_link($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($directory);
    }
}
