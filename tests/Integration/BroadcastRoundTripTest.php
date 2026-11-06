<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Tests\Integration;

use IntrAgile\Observability\Checks\BroadcastCheck;
use IntrAgile\Observability\Tests\TestCase;
use Laravel\Reverb\ReverbServiceProvider;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Spatie\Health\Enums\Status;
use Symfony\Component\Process\Process;
use WebSocket\Client;

/**
 * Runs the broadcast check against a real Reverb server started through Testbench.
 *
 * Opt-in with OBSERVABILITY_REVERB_TEST=1, since it binds a local port and spawns a process.
 * Everything uses 127.0.0.1 rather than localhost: localhost may resolve to ::1 and reach another
 * service listening on the same port.
 */
#[Group('reverb')]
final class BroadcastRoundTripTest extends TestCase
{
    private const HOST = '127.0.0.1';

    private const PORT = 18080;

    private const APP_ID = 'observability-test';

    private const APP_KEY = 'observability-key';

    private const APP_SECRET = 'observability-secret';

    protected function setUp(): void
    {
        if (getenv('OBSERVABILITY_REVERB_TEST') !== '1') {
            $this->markTestSkipped('Set OBSERVABILITY_REVERB_TEST=1 to run against a real Reverb server.');
        }

        if (! class_exists(ReverbServiceProvider::class) || ! class_exists(Client::class)) {
            $this->markTestSkipped('laravel/reverb and phrity/websocket are required.');
        }

        parent::setUp();
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('broadcasting.default', 'reverb');
        $app['config']->set('broadcasting.connections.reverb', [
            'driver' => 'reverb',
            'key' => self::APP_KEY,
            'secret' => self::APP_SECRET,
            'app_id' => self::APP_ID,
            'options' => [
                'host' => self::HOST,
                'port' => self::PORT,
                'scheme' => 'http',
                'useTLS' => false,
            ],
            'client_options' => ['timeout' => 5],
        ]);
    }

    public function test_round_trip_against_real_reverb(): void
    {
        $process = new Process(
            [PHP_BINARY, 'vendor/bin/testbench', 'reverb:start', '--host='.self::HOST, '--port='.self::PORT],
            dirname(__DIR__, 2),
            [
                // The restart-signal check reads the cache; Testbench's default database store has no table.
                'CACHE_STORE' => 'array',
                'REVERB_APP_ID' => self::APP_ID,
                'REVERB_APP_KEY' => self::APP_KEY,
                'REVERB_APP_SECRET' => self::APP_SECRET,
            ],
        );
        $process->setTimeout(60);
        $process->start();

        try {
            $this->waitUntilListening($process);

            $result = BroadcastCheck::new()->connection('reverb')->clientUrl(null)->timeout(5)->run();

            $this->assertSame(
                Status::ok()->value,
                $result->status->value,
                $result->getNotificationMessage()."\nReverb output:\n".$process->getOutput().$process->getErrorOutput(),
            );
            $this->assertIsInt($result->meta['round_trip_ms']);
        } finally {
            $process->stop(3);
        }
    }

    private function waitUntilListening(Process $process): void
    {
        $ready = 'Starting server on '.self::HOST.':'.self::PORT;
        $deadline = microtime(true) + 10;

        while (microtime(true) < $deadline) {
            if (! $process->isRunning()) {
                throw new RuntimeException("Reverb exited before listening:\n".$process->getOutput().$process->getErrorOutput());
            }

            if (str_contains($process->getOutput(), $ready)) {
                return;
            }

            usleep(100_000);
        }

        throw new RuntimeException("Reverb did not start within 10s:\n".$process->getOutput().$process->getErrorOutput());
    }
}
