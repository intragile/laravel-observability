<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Tests\Feature\Doctor;

use Illuminate\Contracts\Foundation\Application;
use IntrAgile\Observability\Broadcast\ProbeOutcome;
use IntrAgile\Observability\Broadcast\ReverbProbe;
use IntrAgile\Observability\Doctor\DoctorOptions;
use IntrAgile\Observability\Doctor\Probes\BroadcastProbe;
use IntrAgile\Observability\Readiness\ReadinessEntry;
use IntrAgile\Observability\Readiness\ReadinessStatus;
use IntrAgile\Observability\Tests\TestCase;

final class BroadcastProbeTest extends TestCase
{
    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app->make('config')->set('broadcasting.connections.reverb', [
            'driver' => 'reverb',
            'key' => 'key',
            'options' => ['host' => '127.0.0.1', 'port' => 1, 'scheme' => 'http'],
        ]);
        $app->make('config')->set('observability.health.checks.broadcast.client_url', 'https://ws.example.test');
    }

    public function test_delivered_round_trip_is_ok_with_its_duration(): void
    {
        $this->fakeReverbProbe(ProbeOutcome::delivered(42.4), expectedTimeout: 1.5);

        $entries = $this->probe(timeout: 1.5);

        $this->assertSame(ReadinessStatus::Ok, $entries[0]->status);
        $this->assertStringContainsString('42 ms', $entries[0]->message);
    }

    public function test_undelivered_round_trip_fails(): void
    {
        $this->fakeReverbProbe(ProbeOutcome::failed('Connection refused'), expectedTimeout: 5.0);

        $entries = $this->probe(timeout: 5.0);

        $this->assertSame(ReadinessStatus::Failed, $entries[0]->status);
        $this->assertStringContainsString('Connection refused', $entries[0]->message);
    }

    public function test_missing_packages_are_skipped(): void
    {
        $probe = new BroadcastProbe(app(), static fn (string $class): bool => false);

        $entries = $probe->run(new DoctorOptions(live: true, sendTest: false, timeout: 1.0));

        $this->assertSame(ReadinessStatus::Skipped, $entries[0]->status);
        $this->assertStringContainsString('laravel/reverb', $entries[0]->message);
    }

    public function test_disabled_check_is_skipped(): void
    {
        config()->set('observability.health.checks.broadcast.enabled', false);

        $this->assertSame(ReadinessStatus::Skipped, $this->probe(timeout: 1.0)[0]->status);
    }

    public function test_unconfigured_connection_fails(): void
    {
        config()->set('broadcasting.connections.reverb', null);

        $this->assertSame(ReadinessStatus::Failed, $this->probe(timeout: 1.0)[0]->status);
    }

    public function test_probe_is_live(): void
    {
        $this->assertTrue((new BroadcastProbe(app()))->live());
    }

    private function fakeReverbProbe(ProbeOutcome $outcome, float $expectedTimeout): void
    {
        $mock = $this->createMock(ReverbProbe::class);
        $mock->expects($this->once())
            ->method('run')
            ->with('reverb', 'https://ws.example.test', $expectedTimeout)
            ->willReturn($outcome);

        app()->instance(ReverbProbe::class, $mock);
    }

    /**
     * @return list<ReadinessEntry>
     */
    private function probe(float $timeout): array
    {
        // Every class counts as installed, so the outcome depends on configuration and the probe.
        return (new BroadcastProbe(app(), static fn (string $class): bool => true))
            ->run(new DoctorOptions(live: true, sendTest: false, timeout: $timeout));
    }
}
