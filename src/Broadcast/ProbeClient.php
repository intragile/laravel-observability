<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Broadcast;

/**
 * A minimal WebSocket client: just enough to speak the Pusher protocol for a single probe.
 *
 * Kept as a seam so the probe logic can be exercised without a socket and so the WebSocket
 * library stays an optional dependency referenced from a single class.
 */
interface ProbeClient
{
    /**
     * Open the connection, giving up after the timeout (seconds).
     */
    public function connect(string $url, float $timeout): void;

    /**
     * Send a text frame.
     */
    public function send(string $frame): void;

    /**
     * Read the next text frame, or return null when none arrived within the timeout (seconds).
     */
    public function receive(float $timeout): ?string;

    /**
     * Close the connection. Safe to call when it was never opened or is already closed.
     */
    public function close(): void;
}
