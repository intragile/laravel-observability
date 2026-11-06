<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Tests\Feature\Doctor;

use Illuminate\Console\Scheduling\Schedule;
use IntrAgile\Observability\Doctor\DoctorOptions;
use IntrAgile\Observability\Doctor\Probes\SchedulingProbe;
use IntrAgile\Observability\Readiness\ReadinessEntry;
use IntrAgile\Observability\Readiness\ReadinessStatus;
use IntrAgile\Observability\Tests\TestCase;
use Spatie\Health\Commands\RunHealthChecksCommand;

final class SchedulingProbeTest extends TestCase
{
    public function test_missing_health_check_schedule_warns(): void
    {
        $entry = $this->entry('Health check schedule');

        $this->assertSame(ReadinessStatus::Warning, $entry->status);
        $this->assertStringContainsString('health:check', $entry->message);
    }

    public function test_scheduled_health_checks_are_ok(): void
    {
        app(Schedule::class)->command(RunHealthChecksCommand::class)->everyMinute();

        $this->assertSame(ReadinessStatus::Ok, $this->entry('Health check schedule')->status);
    }

    public function test_error_digest_schedule_is_skipped_when_digest_is_disabled(): void
    {
        $this->assertSame(ReadinessStatus::Skipped, $this->entry('Error digest schedule')->status);
    }

    public function test_error_digest_schedule_warns_when_enabled_but_not_scheduled(): void
    {
        config()->set('observability.error_digest.enabled', true);

        $this->assertSame(ReadinessStatus::Warning, $this->entry('Error digest schedule')->status);
    }

    public function test_error_digest_schedule_is_ok_when_scheduled(): void
    {
        config()->set('observability.error_digest.enabled', true);
        app(Schedule::class)->command('observability:error-digest')->everyFiveMinutes();

        $this->assertSame(ReadinessStatus::Ok, $this->entry('Error digest schedule')->status);
    }

    private function entry(string $component): ReadinessEntry
    {
        $entries = app(SchedulingProbe::class)->run(new DoctorOptions(live: false, sendTest: false, timeout: 5.0));

        foreach ($entries as $entry) {
            if ($entry->component === $component) {
                return $entry;
            }
        }

        $this->fail("No entry for [{$component}].");
    }
}
