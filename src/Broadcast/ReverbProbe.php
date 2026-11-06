<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Broadcast;

use Illuminate\Contracts\Broadcasting\Factory;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Sends a broadcast through the application's broadcaster and waits for it to come back over the
 * WebSocket server, the same path a browser client depends on.
 *
 * Speaks the Pusher v7 protocol that Reverb implements: connect, wait for the connection to be
 * established, subscribe to a one-off public channel, wait for the subscription to be confirmed,
 * broadcast an event carrying a random nonce and read until that nonce arrives. The timeout bounds
 * the whole exchange, not each step, so an unresponsive server can never stall a health run.
 *
 * Not final so the health check can be tested against a mocked probe.
 */
class ReverbProbe
{
    public const EVENT = 'observability.probe';

    public const CHANNEL_PREFIX = 'observability-probe.';

    private const QUERY = '?protocol=7&client=intragile-observability&version=1.0';

    public function __construct(
        private readonly ProbeClient $client,
        private readonly Factory $broadcast,
    ) {}

    public function run(string $connection, ?string $clientUrl, float $timeout): ProbeOutcome
    {
        $deadline = hrtime(true) + (int) round(max(0.0, $timeout) * 1e9);

        try {
            $this->client->connect($this->url($connection, $clientUrl), $this->remaining($deadline));
            $this->await('pusher:connection_established', $deadline, $timeout);

            $channel = self::CHANNEL_PREFIX.Str::uuid()->toString();
            $nonce = bin2hex(random_bytes(16));

            $this->client->send(json_encode(
                ['event' => 'pusher:subscribe', 'data' => ['channel' => $channel]],
                JSON_THROW_ON_ERROR,
            ));
            $this->await('pusher_internal:subscription_succeeded', $deadline, $timeout);

            $started = hrtime(true);

            $this->broadcast->connection($connection)->broadcast([$channel], self::EVENT, ['nonce' => $nonce]);

            while (($frame = $this->next($deadline)) !== null) {
                if (($frame['event'] ?? null) === self::EVENT && $this->nonceOf($frame) === $nonce) {
                    return ProbeOutcome::delivered((hrtime(true) - $started) / 1e6);
                }
            }

            return ProbeOutcome::failed('No probe message received within '.self::seconds($timeout).'s.');
        } catch (Throwable $e) {
            return ProbeOutcome::failed($e->getMessage());
        } finally {
            try {
                $this->client->close();
            } catch (Throwable) {
                // The outcome is already decided; a failing close must not replace it.
            }
        }
    }

    /**
     * The WebSocket URL a client of the given broadcasting connection would use.
     *
     * The connection config describes how the server reaches Reverb, which is not always how a
     * client does (behind a proxy, for instance), so a client URL may override scheme, host and port.
     */
    public function url(string $connection, ?string $clientUrl = null): string
    {
        $config = config("broadcasting.connections.{$connection}");

        if (! is_array($config)) {
            throw new InvalidArgumentException("Broadcast connection [{$connection}] is not configured.");
        }

        $key = $config['key'] ?? null;

        if (! is_string($key) || $key === '') {
            throw new InvalidArgumentException("Broadcast connection [{$connection}] has no key.");
        }

        if ($clientUrl !== null && trim($clientUrl) !== '') {
            [$scheme, $host, $port] = $this->fromClientUrl($clientUrl);
        } else {
            [$scheme, $host, $port] = $this->fromOptions($connection, $config['options'] ?? []);
        }

        return "{$scheme}://{$host}:{$port}/app/".rawurlencode($key).self::QUERY;
    }

    /**
     * Read frames until one with the given event arrives.
     */
    private function await(string $event, int $deadline, float $timeout): void
    {
        while (($frame = $this->next($deadline)) !== null) {
            if (($frame['event'] ?? null) === $event) {
                return;
            }
        }

        throw new RuntimeException('Timed out after '.self::seconds($timeout)."s waiting for {$event}.");
    }

    /**
     * The next meaningful frame, or null once the deadline has passed.
     *
     * @return array<array-key, mixed>|null
     *
     * @throws RuntimeException on a protocol error reported by the server or an unreadable frame
     */
    private function next(int $deadline): ?array
    {
        while (($remaining = $this->remaining($deadline)) > 0.0) {
            $raw = $this->client->receive($remaining);

            if ($raw === null) {
                continue;
            }

            $frame = json_decode($raw, true);

            if (! is_array($frame)) {
                throw new RuntimeException('Received a frame that is not a Pusher message.');
            }

            $event = $frame['event'] ?? null;

            if ($event === 'pusher:ping' || $event === 'pusher:pong') {
                continue;
            }

            if ($event === 'pusher:error') {
                throw new RuntimeException($this->errorMessage($frame));
            }

            return $frame;
        }

        return null;
    }

    /**
     * @param  array<array-key, mixed>  $frame
     */
    private function nonceOf(array $frame): ?string
    {
        $data = $frame['data'] ?? null;

        if (is_string($data)) {
            $data = json_decode($data, true);
        }

        $nonce = is_array($data) ? ($data['nonce'] ?? null) : null;

        return is_string($nonce) ? $nonce : null;
    }

    /**
     * @param  array<array-key, mixed>  $frame
     */
    private function errorMessage(array $frame): string
    {
        $data = $frame['data'] ?? null;

        if (is_string($data)) {
            $data = json_decode($data, true) ?? $data;
        }

        $message = is_array($data) ? ($data['message'] ?? null) : $data;

        return is_string($message) && $message !== '' ? $message : 'The WebSocket server reported an error.';
    }

    /**
     * @return array{string, string, int}
     */
    private function fromClientUrl(string $clientUrl): array
    {
        $parts = parse_url(trim($clientUrl));
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;

        if (! is_array($parts) || ! is_string($host) || $host === '') {
            throw new InvalidArgumentException("Broadcast client URL [{$clientUrl}] is not a valid URL.");
        }

        $scheme = $this->webSocketScheme($parts['scheme'] ?? 'https');

        return [$scheme, $host, $parts['port'] ?? $this->defaultPort($scheme)];
    }

    /**
     * @return array{string, string, int}
     */
    private function fromOptions(string $connection, mixed $options): array
    {
        $options = is_array($options) ? $options : [];
        $host = $options['host'] ?? null;

        if (! is_string($host) || $host === '') {
            throw new InvalidArgumentException("Broadcast connection [{$connection}] has no host.");
        }

        $scheme = $options['scheme'] ?? 'https';
        $scheme = $this->webSocketScheme(is_string($scheme) && $scheme !== '' ? $scheme : 'https');
        $port = $options['port'] ?? null;

        return [$scheme, $host, is_numeric($port) ? (int) $port : $this->defaultPort($scheme)];
    }

    private function webSocketScheme(string $scheme): string
    {
        return match (strtolower($scheme)) {
            'http', 'ws' => 'ws',
            'https', 'wss' => 'wss',
            default => throw new InvalidArgumentException("Unsupported broadcast scheme [{$scheme}]."),
        };
    }

    private function defaultPort(string $scheme): int
    {
        return $scheme === 'wss' ? 443 : 80;
    }

    private function remaining(int $deadline): float
    {
        return max(0.0, ($deadline - hrtime(true)) / 1e9);
    }

    private static function seconds(float $seconds): string
    {
        return rtrim(rtrim(number_format($seconds, 3, '.', ''), '0'), '.');
    }
}
