<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Metrics;

use IntrAgile\Observability\Support\ContainerMemory;
use Spatie\Prometheus\Collectors\Queue\QueueDelayedJobsCollector;
use Spatie\Prometheus\Collectors\Queue\QueueOldestPendingJobCollector;
use Spatie\Prometheus\Collectors\Queue\QueuePendingJobsCollector;
use Spatie\Prometheus\Collectors\Queue\QueueReservedJobsCollector;
use Spatie\Prometheus\Collectors\Queue\QueueSizeCollector;
use Spatie\Prometheus\Prometheus;

/**
 * Registers the package's metrics with spatie/laravel-prometheus.
 *
 * Only call this when spatie/laravel-prometheus is installed. The metric names are prefixed by
 * Spatie with `prometheus.default_namespace` (for example `app_container_memory_limit_bytes`).
 */
final class PrometheusRegistrar
{
    public function __construct(private readonly ContainerMemory $memory) {}

    /**
     * @param  array<string, mixed>  $config  the `observability.prometheus` configuration array
     */
    public function register(array $config): void
    {
        $prometheus = app(Prometheus::class);

        if (($config['container_memory_gauges'] ?? false) === true) {
            $this->registerContainerMemoryGauges($prometheus);
        }

        $queues = $config['queue_collectors'] ?? [];

        if (is_array($queues) && ($queues['enabled'] ?? false) === true) {
            $this->registerQueueCollectors($prometheus, $queues);
        }
    }

    private function registerContainerMemoryGauges(Prometheus $prometheus): void
    {
        // Without a limit (development machine, unreadable cgroup) the gauges carry no meaning.
        if ($this->memory->limitBytes() === null) {
            return;
        }

        $prometheus->addGauge('Container memory limit bytes')
            ->name('container_memory_limit_bytes')
            ->helpText('The cgroup memory limit of the container in bytes')
            ->value(fn (): float|array => self::sample($this->memory->limitBytes()));

        $prometheus->addGauge('Container memory used bytes')
            ->name('container_memory_used_bytes')
            ->helpText('The anonymous memory used by the container in bytes, excluding page cache')
            ->value(fn (): float|array => self::sample($this->memory->usedBytes()));
    }

    /**
     * @param  array<mixed>  $queues
     */
    private function registerQueueCollectors(Prometheus $prometheus, array $queues): void
    {
        $connection = $queues['connection'] ?? null;
        $names = is_array($queues['queues'] ?? null) ? $queues['queues'] : [];

        $prometheus->registerCollectorClasses(
            [
                QueueSizeCollector::class,
                QueuePendingJobsCollector::class,
                QueueDelayedJobsCollector::class,
                QueueReservedJobsCollector::class,
                QueueOldestPendingJobCollector::class,
            ],
            [
                'connection' => is_string($connection) && $connection !== '' ? $connection : null,
                'queues' => array_values(array_filter($names, 'is_string')),
            ],
        );
    }

    /**
     * Spatie skips an empty array, whereas a null value would make the underlying gauge throw.
     *
     * @return float|array{}
     */
    private static function sample(?int $bytes): float|array
    {
        return $bytes === null ? [] : (float) $bytes;
    }
}
