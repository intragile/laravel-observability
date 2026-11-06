<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Tests\Unit\Broadcast;

use Closure;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Contracts\Broadcasting\Factory;
use IntrAgile\Observability\Broadcast\ProbeClient;
use IntrAgile\Observability\Broadcast\ReverbProbe;
use IntrAgile\Observability\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Throwable;

final class ReverbProbeTest extends TestCase
{
    private FakeProbeClient $client;

    private FakeBroadcastFactory $broadcast;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = new FakeProbeClient;
        $this->broadcast = new FakeBroadcastFactory;
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('broadcasting.connections.reverb', [
            'driver' => 'reverb',
            'key' => 'probe-key',
            'secret' => 'probe-secret',
            'app_id' => 'probe-app',
            'options' => [
                'host' => 'reverb.test',
                'port' => 8080,
                'scheme' => 'http',
                'useTLS' => false,
            ],
        ]);
    }

    public function test_delivers_when_frames_arrive_in_order(): void
    {
        $this->handshake();
        $this->broadcast->onBroadcast = fn (string $channel, string $nonce) => $this->client->push(
            self::probeFrame($channel, $nonce),
        );

        $outcome = $this->probe()->run('reverb', null, 2.0);

        $this->assertTrue($outcome->delivered);
        $this->assertNull($outcome->error);
        $this->assertNotNull($outcome->roundTripMs);
        $this->assertGreaterThanOrEqual(0.0, $outcome->roundTripMs);
        $this->assertSame(1, $this->client->closed);

        $subscribe = json_decode($this->client->sent[0], true);
        $this->assertIsArray($subscribe);
        $this->assertSame('pusher:subscribe', $subscribe['event']);
        $this->assertIsArray($subscribe['data']);
        $channel = $subscribe['data']['channel'];
        $this->assertIsString($channel);
        $this->assertMatchesRegularExpression('/^observability-probe\.[0-9a-f-]{36}$/', $channel);

        $this->assertSame([$channel], $this->broadcast->broadcaster->channels);
        $this->assertSame('observability.probe', $this->broadcast->broadcaster->event);
        $this->assertSame('reverb', $this->broadcast->connection);
    }

    public function test_ignores_foreign_nonce_then_delivers(): void
    {
        $this->handshake();
        $this->broadcast->onBroadcast = function (string $channel, string $nonce): void {
            $this->client->push('{"event":"pusher:ping","data":{}}');
            $this->client->push(self::probeFrame($channel, 'someone-else'));
            $this->client->push('{"event":"other.event","channel":"'.$channel.'","data":"{}"}');
            $this->client->push(self::probeFrame($channel, $nonce));
        };

        $outcome = $this->probe()->run('reverb', null, 2.0);

        $this->assertTrue($outcome->delivered);
        $this->assertSame([], $this->client->frames);
        $this->assertSame(1, $this->client->closed);
    }

    public function test_fails_within_timeout_when_no_message_arrives(): void
    {
        $this->handshake();
        $this->broadcast->onBroadcast = fn (string $channel) => $this->client->push(
            self::probeFrame($channel, 'someone-else'),
        );

        $started = microtime(true);
        $outcome = $this->probe()->run('reverb', null, 1.0);
        $elapsed = microtime(true) - $started;

        $this->assertFalse($outcome->delivered);
        $this->assertNull($outcome->roundTripMs);
        $this->assertSame('No probe message received within 1s.', $outcome->error);
        $this->assertLessThan(1.5, $elapsed);
        $this->assertSame(1, $this->client->closed);
    }

    public function test_fails_when_connect_throws(): void
    {
        $this->client->connectThrows = new RuntimeException('Could not connect to tcp://reverb.test:8080');

        $outcome = $this->probe()->run('reverb', null, 1.0);

        $this->assertFalse($outcome->delivered);
        $this->assertNull($outcome->roundTripMs);
        $this->assertSame('Could not connect to tcp://reverb.test:8080', $outcome->error);
        $this->assertNull($this->broadcast->broadcaster->event);
        $this->assertSame(1, $this->client->closed);
    }

    public function test_fails_when_broadcaster_throws(): void
    {
        $this->handshake();
        $this->broadcast->broadcaster->throws = new RuntimeException('Pusher error: 500.');

        $outcome = $this->probe()->run('reverb', null, 1.0);

        $this->assertFalse($outcome->delivered);
        $this->assertSame('Pusher error: 500.', $outcome->error);
        $this->assertSame(1, $this->client->closed);
    }

    public function test_fails_on_pusher_error_frame(): void
    {
        $this->client->push('{"event":"pusher:error","data":{"message":"Application does not exist","code":4001}}');

        $outcome = $this->probe()->run('reverb', null, 1.0);

        $this->assertFalse($outcome->delivered);
        $this->assertSame('Application does not exist', $outcome->error);
        $this->assertSame(1, $this->client->closed);
    }

    public function test_builds_url_from_connection_config(): void
    {
        $this->probe()->run('reverb', null, 0.1);

        $this->assertSame(
            'ws://reverb.test:8080/app/probe-key?protocol=7&client=intragile-observability&version=1.0',
            $this->client->url,
        );
    }

    public function test_defaults_port_from_scheme(): void
    {
        config()->set('broadcasting.connections.reverb.options', ['host' => 'reverb.test', 'scheme' => 'https']);

        $this->probe()->run('reverb', null, 0.1);

        $this->assertSame(
            'wss://reverb.test:443/app/probe-key?protocol=7&client=intragile-observability&version=1.0',
            $this->client->url,
        );
    }

    public function test_client_url_overrides_scheme_host_and_port(): void
    {
        $this->probe()->run('reverb', 'https://ws.example.com:6001', 0.1);

        $this->assertSame(
            'wss://ws.example.com:6001/app/probe-key?protocol=7&client=intragile-observability&version=1.0',
            $this->client->url,
        );

        $this->probe()->run('reverb', 'http://ws.example.com', 0.1);

        $this->assertSame(
            'ws://ws.example.com:80/app/probe-key?protocol=7&client=intragile-observability&version=1.0',
            $this->client->url,
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function paths(): array
    {
        return [
            'delivered' => ['delivered'],
            'timeout' => ['timeout'],
            'connect throws' => ['connect'],
            'send throws' => ['send'],
            'broadcaster throws' => ['broadcast'],
            'pusher error' => ['pusher-error'],
            'malformed frame' => ['malformed'],
        ];
    }

    #[DataProvider('paths')]
    public function test_closes_connection_on_every_path(string $path): void
    {
        match ($path) {
            'delivered' => $this->handshakeThenDeliver(),
            'timeout' => $this->handshake(),
            'connect' => $this->client->connectThrows = new RuntimeException('refused'),
            'send' => $this->client->sendThrows = new RuntimeException('broken pipe'),
            'broadcast' => $this->broadcast->broadcaster->throws = new RuntimeException('down'),
            'pusher-error' => $this->client->push('{"event":"pusher:error","data":{"message":"nope"}}'),
            'malformed' => $this->client->push('not json'),
            default => null,
        };

        $outcome = $this->probe()->run('reverb', null, 0.3);

        $this->assertSame($path === 'delivered', $outcome->delivered);
        $this->assertSame(1, $this->client->closed);
    }

    private function probe(): ReverbProbe
    {
        return new ReverbProbe($this->client, $this->broadcast);
    }

    private function handshake(): void
    {
        $this->client->push('{"event":"pusher:connection_established","data":"{\"socket_id\":\"1.2\",\"activity_timeout\":30}"}');
        $this->client->onSend = function (string $frame): void {
            $decoded = json_decode($frame, true);

            if (is_array($decoded) && ($decoded['event'] ?? null) === 'pusher:subscribe' && is_array($decoded['data'])) {
                $channel = $decoded['data']['channel'];
                $this->client->push(
                    '{"event":"pusher_internal:subscription_succeeded","channel":"'.(is_string($channel) ? $channel : '').'","data":"{}"}',
                );
            }
        };
    }

    private function handshakeThenDeliver(): void
    {
        $this->handshake();
        $this->broadcast->onBroadcast = fn (string $channel, string $nonce) => $this->client->push(
            self::probeFrame($channel, $nonce),
        );
    }

    private static function probeFrame(string $channel, string $nonce): string
    {
        return (string) json_encode([
            'event' => 'observability.probe',
            'channel' => $channel,
            'data' => json_encode(['nonce' => $nonce]),
        ]);
    }
}

/**
 * In-memory socket: frames are queued by the test and an empty queue behaves like a silent server.
 */
final class FakeProbeClient implements ProbeClient
{
    /** @var list<string> */
    public array $frames = [];

    /** @var list<string> */
    public array $sent = [];

    public int $closed = 0;

    public ?string $url = null;

    public ?Throwable $connectThrows = null;

    public ?Throwable $sendThrows = null;

    /** @var (Closure(string): void)|null */
    public ?Closure $onSend = null;

    public function push(string $frame): void
    {
        $this->frames[] = $frame;
    }

    public function connect(string $url, float $timeout): void
    {
        $this->url = $url;

        if ($this->connectThrows !== null) {
            throw $this->connectThrows;
        }
    }

    public function send(string $frame): void
    {
        if ($this->sendThrows !== null) {
            throw $this->sendThrows;
        }

        $this->sent[] = $frame;

        if ($this->onSend !== null) {
            ($this->onSend)($frame);
        }
    }

    public function receive(float $timeout): ?string
    {
        if ($this->frames === []) {
            usleep((int) max(0, $timeout * 1_000_000));

            return null;
        }

        return array_shift($this->frames);
    }

    public function close(): void
    {
        $this->closed++;
    }
}

final class FakeBroadcastFactory implements Factory
{
    public FakeBroadcaster $broadcaster;

    public ?string $connection = null;

    /** @var (Closure(string, string): void)|null */
    public ?Closure $onBroadcast = null;

    public function __construct()
    {
        $this->broadcaster = new FakeBroadcaster($this);
    }

    public function connection($name = null): Broadcaster
    {
        $this->connection = is_string($name) ? $name : null;

        return $this->broadcaster;
    }
}

final class FakeBroadcaster implements Broadcaster
{
    /** @var array<array-key, mixed> */
    public array $channels = [];

    public ?string $event = null;

    /** @var array<array-key, mixed> */
    public array $payload = [];

    public ?Throwable $throws = null;

    public function __construct(private readonly FakeBroadcastFactory $factory) {}

    public function auth($request): mixed
    {
        return null;
    }

    public function validAuthenticationResponse($request, $result): mixed
    {
        return null;
    }

    /**
     * @param  array<array-key, mixed>  $channels
     * @param  string  $event
     * @param  array<array-key, mixed>  $payload
     */
    public function broadcast(array $channels, $event, array $payload = []): void
    {
        if ($this->throws !== null) {
            throw $this->throws;
        }

        $this->channels = $channels;
        $this->event = $event;
        $this->payload = $payload;

        $channel = $channels[0] ?? '';
        $nonce = $payload['nonce'] ?? '';

        if ($this->factory->onBroadcast !== null && is_string($channel) && is_string($nonce)) {
            ($this->factory->onBroadcast)($channel, $nonce);
        }
    }
}
