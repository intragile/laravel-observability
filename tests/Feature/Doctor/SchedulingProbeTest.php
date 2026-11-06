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
    private ?string $flagDirectory = null;

    protected function tearDown(): void
    {
        if ($this->flagDirectory !== null) {
            foreach (glob($this->flagDirectory.'/*') ?: [] as $file) {
                unlink($file);
            }

            rmdir($this->flagDirectory);
        }

        parent::tearDown();
    }

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

    public function test_health_check_held_back_during_warmup_is_skipped(): void
    {
        $this->configureWarmup(enabled: true, deployedAt: time());

        $entry = $this->entry('Health check schedule');

        $this->assertSame(ReadinessStatus::Skipped, $entry->status);
        $this->assertStringContainsString('held back for', $entry->message);
        $this->assertStringContainsString('warmup', $entry->message);
    }

    public function test_missing_schedule_still_warns_after_warmup(): void
    {
        $this->configureWarmup(enabled: true, deployedAt: time() - 1000);

        $this->assertSame(ReadinessStatus::Warning, $this->entry('Health check schedule')->status);
    }

    public function test_missing_schedule_warns_when_schedule_disabled_even_with_fresh_flag(): void
    {
        $this->configureWarmup(enabled: false, deployedAt: time());

        $this->assertSame(ReadinessStatus::Warning, $this->entry('Health check schedule')->status);
    }

    public function test_missing_flag_warns_instead_of_reporting_warmup(): void
    {
        $this->configureWarmup(enabled: true, deployedAt: null);

        $entry = $this->entry('Health check schedule');

        $this->assertSame(ReadinessStatus::Warning, $entry->status);
        $this->assertStringContainsString('no scheduler tick', $entry->message);
    }

    public function test_flag_from_another_deploy_warns_instead_of_reporting_warmup(): void
    {
        $this->configureWarmup(enabled: true, deployedAt: time(), flagDeployId: 'deploy-a');
        config()->set('observability.deploy_id', 'deploy-b');

        $this->assertSame(ReadinessStatus::Warning, $this->entry('Health check schedule')->status);
    }

    public function test_flag_from_current_deploy_is_skipped_during_warmup(): void
    {
        $this->configureWarmup(enabled: true, deployedAt: time(), flagDeployId: 'deploy-b');
        config()->set('observability.deploy_id', 'deploy-b');

        $this->assertSame(ReadinessStatus::Skipped, $this->entry('Health check schedule')->status);
    }

    private function configureWarmup(bool $enabled, ?int $deployedAt, ?string $flagDeployId = null): void
    {
        $this->flagDirectory = sys_get_temp_dir().'/obs-probe-'.bin2hex(random_bytes(6));
        mkdir($this->flagDirectory, 0775, true);
        $flag = $this->flagDirectory.'/health-warmup.json';

        if ($deployedAt !== null) {
            file_put_contents($flag, (string) json_encode(['deployed_at' => $deployedAt, 'deploy_id' => $flagDeployId]));
        }

        config()->set('observability.health.schedule.enabled', $enabled);
        config()->set('observability.health.schedule.flag_path', $flag);
        config()->set('observability.health.schedule.warmup_seconds', 300);
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
