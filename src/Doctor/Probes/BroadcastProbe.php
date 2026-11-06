<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Doctor\Probes;

use Closure;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastFactory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use IntrAgile\Observability\Broadcast\PhrityProbeClient;
use IntrAgile\Observability\Broadcast\ProbeClient;
use IntrAgile\Observability\Broadcast\ReverbProbe;
use IntrAgile\Observability\Doctor\DoctorOptions;
use IntrAgile\Observability\Doctor\Probe;
use IntrAgile\Observability\Readiness\Inspector;
use IntrAgile\Observability\Readiness\ReadinessEntry;
use IntrAgile\Observability\Readiness\ReadinessStatus;

/**
 * Broadcasts through the application and waits for the message on the WebSocket server, using the
 * same round trip as the broadcast health check, bounded by the doctor timeout.
 */
final class BroadcastProbe implements Probe
{
    /** @var Closure(string): bool */
    private readonly Closure $classExists;

    /**
     * @param  (Closure(string): bool)|null  $classExists  detects the optional packages; defaults to class_exists()
     */
    public function __construct(
        private readonly Container $app,
        ?Closure $classExists = null,
    ) {
        $this->classExists = $classExists ?? static fn (string $class): bool => class_exists($class);
    }

    public function name(): string
    {
        return 'Broadcast probe';
    }

    public function live(): bool
    {
        return true;
    }

    public function run(DoctorOptions $options): array
    {
        $component = 'Broadcast round trip';
        $config = $this->app->make(Repository::class);

        $missing = array_keys(array_filter(
            ['laravel/reverb' => Inspector::REVERB_CLASS, 'phrity/websocket' => Inspector::WEBSOCKET_CLIENT_CLASS],
            fn (string $class): bool => ! ($this->classExists)($class),
        ));

        if ($missing !== []) {
            return [new ReadinessEntry($component, ReadinessStatus::Skipped, 'Requires laravel/reverb and phrity/websocket; missing: '.implode(', ', $missing).'.')];
        }

        if (! filter_var($config->get('observability.health.checks.broadcast.enabled'), FILTER_VALIDATE_BOOLEAN)) {
            return [new ReadinessEntry($component, ReadinessStatus::Skipped, 'Disabled by observability.health.checks.broadcast.enabled.')];
        }

        $connection = $config->get('observability.health.checks.broadcast.connection');
        $connection = is_string($connection) && $connection !== '' ? $connection : 'reverb';

        if (! is_array($config->get("broadcasting.connections.{$connection}"))) {
            return [new ReadinessEntry($component, ReadinessStatus::Failed, "Broadcast connection [{$connection}] is not configured.")];
        }

        $clientUrl = $config->get('observability.health.checks.broadcast.client_url');
        $clientUrl = is_string($clientUrl) && trim($clientUrl) !== '' ? $clientUrl : null;

        $outcome = $this->reverbProbe()->run($connection, $clientUrl, $options->timeout);

        if (! $outcome->delivered || $outcome->roundTripMs === null) {
            return [new ReadinessEntry($component, ReadinessStatus::Failed, 'The broadcast was not delivered: '.($outcome->error ?? 'unknown error.'))];
        }

        return [new ReadinessEntry($component, ReadinessStatus::Ok, 'Broadcast round trip took '.(int) round($outcome->roundTripMs)." ms over [{$connection}].")];
    }

    private function reverbProbe(): ReverbProbe
    {
        if ($this->app->bound(ReverbProbe::class)) {
            return $this->app->make(ReverbProbe::class);
        }

        $client = $this->app->bound(ProbeClient::class) ? $this->app->make(ProbeClient::class) : new PhrityProbeClient;

        return new ReverbProbe($client, $this->app->make(BroadcastFactory::class));
    }
}
