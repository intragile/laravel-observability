<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Tests\Unit\Checks;

use IntrAgile\Observability\Broadcast\ProbeOutcome;
use IntrAgile\Observability\Broadcast\ReverbProbe;
use IntrAgile\Observability\Checks\BroadcastCheck;
use IntrAgile\Observability\Tests\TestCase;
use Spatie\Health\Enums\Status;

final class BroadcastCheckTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('broadcasting.connections.reverb', [
            'driver' => 'reverb',
            'key' => 'probe-key',
            'secret' => 'probe-secret',
            'app_id' => 'probe-app',
            'options' => ['host' => 'reverb.test', 'port' => 8080, 'scheme' => 'http'],
        ]);
    }

    public function test_ok_with_round_trip_meta(): void
    {
        $this->expectProbe(new ProbeOutcome(true, 40.4, null), 'reverb', 'https://ws.example.com', 3.0);

        $result = BroadcastCheck::new()
            ->connection('reverb')
            ->clientUrl('https://ws.example.com')
            ->timeout(3.0)
            ->warnWhenSlowerThan(1000)
            ->run();

        $this->assertSame(Status::ok()->value, $result->status->value);
        $this->assertSame(40, $result->meta['round_trip_ms']);
        $this->assertSame('40 ms', $result->getShortSummary());
    }

    public function test_warning_when_slower_than_threshold(): void
    {
        $this->expectProbe(new ProbeOutcome(true, 1500.0, null), 'reverb', null, 5.0);

        $result = BroadcastCheck::new()->warnWhenSlowerThan(1000)->run();

        $this->assertSame(Status::warning()->value, $result->status->value);
        $this->assertSame(1500, $result->meta['round_trip_ms']);
        $this->assertStringContainsString('1500 ms', $result->getNotificationMessage());
    }

    public function test_uses_configured_defaults(): void
    {
        config()->set('observability.health.checks.broadcast', [
            'connection' => 'reverb',
            'client_url' => 'http://127.0.0.1:18080',
            'timeout_seconds' => 2.5,
            'warn_ms' => 20,
        ]);

        $this->expectProbe(new ProbeOutcome(true, 40.0, null), 'reverb', 'http://127.0.0.1:18080', 2.5);

        $result = BroadcastCheck::new()->run();

        $this->assertSame(Status::warning()->value, $result->status->value);
    }

    public function test_failed_when_not_delivered(): void
    {
        $this->expectProbe(new ProbeOutcome(false, null, 'No probe message received within 5s.'), 'reverb', null, 5.0);

        $result = BroadcastCheck::new()->run();

        $this->assertSame(Status::failed()->value, $result->status->value);
        $this->assertStringContainsString('No probe message received within 5s.', $result->getNotificationMessage());
    }

    public function test_failed_when_connection_is_not_configured(): void
    {
        $probe = $this->createMock(ReverbProbe::class);
        $probe->expects($this->never())->method('run');
        $this->instance(ReverbProbe::class, $probe);

        $result = BroadcastCheck::new()->connection('x')->run();

        $this->assertSame(Status::failed()->value, $result->status->value);
        $this->assertSame('Broadcast connection [x] is not configured.', $result->getNotificationMessage());
    }

    private function expectProbe(ProbeOutcome $outcome, string $connection, ?string $clientUrl, float $timeout): void
    {
        $probe = $this->createMock(ReverbProbe::class);
        $probe->expects($this->once())
            ->method('run')
            ->with($connection, $clientUrl, $timeout)
            ->willReturn($outcome);

        $this->instance(ReverbProbe::class, $probe);
    }
}
