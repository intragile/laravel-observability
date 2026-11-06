<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Checks;

use Illuminate\Contracts\Broadcasting\Factory as BroadcastFactory;
use IntrAgile\Observability\Broadcast\PhrityProbeClient;
use IntrAgile\Observability\Broadcast\ProbeClient;
use IntrAgile\Observability\Broadcast\ReverbProbe;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Verifies that a broadcast actually reaches a WebSocket client.
 *
 * Instead of trusting that an event was dispatched, the check subscribes to a one-off channel on
 * the Reverb server, broadcasts through the application's broadcaster and waits for the event to
 * arrive. It fails within the timeout when Reverb is down, unreachable or silent.
 *
 * Settings left unset fall back to `observability.health.checks.broadcast`. Only scalars are kept on
 * the check, because checks are serialized; the probe is resolved when the check runs.
 */
final class BroadcastCheck extends Check
{
    private ?string $connection = null;

    private ?string $clientUrl = null;

    private bool $clientUrlSet = false;

    private ?float $timeout = null;

    private ?int $warnMs = null;

    public function connection(string $connection): static
    {
        $this->connection = $connection;

        return $this;
    }

    /**
     * The URL a browser uses to reach Reverb, when it differs from the server-side connection
     * config (behind a proxy, for instance). Only scheme, host and port are used.
     */
    public function clientUrl(?string $url): static
    {
        $this->clientUrl = $url === null || trim($url) === '' ? null : $url;
        $this->clientUrlSet = true;

        return $this;
    }

    public function timeout(float $seconds): static
    {
        $this->timeout = $seconds;

        return $this;
    }

    public function warnWhenSlowerThan(int $milliseconds): static
    {
        $this->warnMs = $milliseconds;

        return $this;
    }

    public function run(): Result
    {
        $connection = $this->connection ?? $this->configString('connection') ?? 'reverb';
        $clientUrl = $this->clientUrlSet ? $this->clientUrl : $this->configString('client_url');
        $timeout = $this->timeout ?? $this->configFloat('timeout_seconds') ?? 5.0;
        $warnMs = $this->warnMs ?? (int) ($this->configFloat('warn_ms') ?? 1000);

        $result = Result::make()->meta(['connection' => $connection]);

        if (! is_array(config("broadcasting.connections.{$connection}"))) {
            return $result
                ->shortSummary('not configured')
                ->failed("Broadcast connection [{$connection}] is not configured.");
        }

        $outcome = $this->probe()->run($connection, $clientUrl, $timeout);

        if (! $outcome->delivered || $outcome->roundTripMs === null) {
            return $result
                ->shortSummary('not delivered')
                ->failed('Broadcast was not delivered: '.($outcome->error ?? 'unknown error.'));
        }

        $roundTripMs = (int) round($outcome->roundTripMs);

        $result
            ->shortSummary("{$roundTripMs} ms")
            ->appendMeta(['round_trip_ms' => $roundTripMs]);

        if ($roundTripMs > $warnMs) {
            return $result->warning("Broadcast round trip took {$roundTripMs} ms, more than {$warnMs} ms.");
        }

        return $result->ok("Broadcast round trip took {$roundTripMs} ms.");
    }

    private function probe(): ReverbProbe
    {
        if (app()->bound(ReverbProbe::class)) {
            return app(ReverbProbe::class);
        }

        $client = app()->bound(ProbeClient::class) ? app(ProbeClient::class) : new PhrityProbeClient;

        return new ReverbProbe($client, app(BroadcastFactory::class));
    }

    private function configString(string $key): ?string
    {
        $value = config("observability.health.checks.broadcast.{$key}");

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private function configFloat(string $key): ?float
    {
        $value = config("observability.health.checks.broadcast.{$key}");

        return is_numeric($value) ? (float) $value : null;
    }
}
