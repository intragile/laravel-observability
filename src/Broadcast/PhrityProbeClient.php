<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Broadcast;

use Closure;
use RuntimeException;
use WebSocket\Client;
use WebSocket\Connection;
use WebSocket\Exception\ConnectionTimeoutException;
use WebSocket\Message\Text;
use WebSocket\Middleware\CloseHandler;
use WebSocket\Middleware\PingResponder;

/**
 * ProbeClient backed by phrity/websocket.
 *
 * The library takes its read timeout from the configuration once, when the connection is created,
 * and Client::receive() accepts no timeout. Each receive() therefore sets the timeout on the
 * underlying stream directly, reached through the client's protected connection accessor.
 */
final class PhrityProbeClient implements ProbeClient
{
    private ?Client $client = null;

    public function connect(string $url, float $timeout): void
    {
        $this->close();

        $client = new Client($url);
        $client->setTimeout(max(0.001, $timeout));
        $client->addMiddleware(new CloseHandler);
        $client->addMiddleware(new PingResponder);
        $client->connect();

        $this->client = $client;
    }

    public function send(string $frame): void
    {
        $this->client()->send(new Text($frame));
    }

    public function receive(float $timeout): ?string
    {
        $client = $this->client();
        $deadline = microtime(true) + $timeout;

        while (($remaining = $deadline - microtime(true)) > 0) {
            // connection() reconnects a dropped client; a probe must report the drop instead.
            if (! $client->isConnected()) {
                throw new RuntimeException('The WebSocket connection was closed by the server.');
            }

            $this->connectionOf($client)->getStream()->setTimeout($remaining);

            try {
                $message = $client->receive();
            } catch (ConnectionTimeoutException) {
                return null;
            }

            // Control frames (ping, pong, close) are handled by the middleware; only text matters here.
            if ($message instanceof Text) {
                return $message->getContent();
            }
        }

        return null;
    }

    public function close(): void
    {
        $client = $this->client;
        $this->client = null;

        if ($client !== null && $client->isConnected()) {
            $client->disconnect();
        }
    }

    private function client(): Client
    {
        return $this->client ?? throw new RuntimeException('The WebSocket connection is not open.');
    }

    private function connectionOf(Client $client): Connection
    {
        $accessor = Closure::bind(fn (): Connection => $this->connection(), $client, Client::class);

        return $accessor();
    }
}
