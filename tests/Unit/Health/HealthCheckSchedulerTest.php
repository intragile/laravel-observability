<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Tests\Unit\Health;

use Illuminate\Config\Repository;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Exceptions;
use IntrAgile\Observability\Health\HealthCheckScheduler;
use IntrAgile\Observability\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

final class HealthCheckSchedulerTest extends TestCase
{
    private string $dir;

    private string $flag;

    private int $now = 1_000_000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/obs-warmup-'.bin2hex(random_bytes(6));
        $this->flag = $this->dir.'/nested/health-warmup.json';
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->dir);

        parent::tearDown();
    }

    public function test_first_tick_writes_flag_and_holds_back(): void
    {
        $scheduler = $this->scheduler(300);

        $this->assertFalse($scheduler->shouldSchedule());
        $this->assertSame(['deployed_at' => 1_000_000, 'deploy_id' => null], $this->readJson());
    }

    public function test_inside_warmup_holds_back(): void
    {
        $this->writeFlag((string) json_encode(['deployed_at' => $this->now - 100, 'deploy_id' => null]));
        $scheduler = $this->scheduler(300);

        $this->assertFalse($scheduler->shouldSchedule());
        $this->assertSame(200, $scheduler->remainingWarmup());
    }

    public function test_after_warmup_schedules(): void
    {
        $this->writeFlag((string) json_encode(['deployed_at' => $this->now - 300, 'deploy_id' => null]));
        $scheduler = $this->scheduler(300);

        $this->assertTrue($scheduler->shouldSchedule());
        $this->assertSame(0, $scheduler->remainingWarmup());
    }

    public function test_time_passing_releases_the_schedule(): void
    {
        $scheduler = $this->scheduler(300);

        $this->assertFalse($scheduler->shouldSchedule());

        $this->now += 299;
        $this->assertFalse($scheduler->shouldSchedule());
        $this->assertSame(1, $scheduler->remainingWarmup());

        $this->now += 1;
        $this->assertTrue($scheduler->shouldSchedule());
    }

    public function test_legacy_integer_flag_is_read(): void
    {
        $this->writeFlag('999900');
        $scheduler = $this->scheduler(300);

        $this->assertFalse($scheduler->shouldSchedule());
        $this->assertSame(200, $scheduler->remainingWarmup());
        $this->assertSame('999900', $this->readRaw());
    }

    public function test_legacy_flag_with_deploy_id_restarts_warmup(): void
    {
        $this->writeFlag('100');
        $scheduler = $this->scheduler(300, 'abc');

        $this->assertFalse($scheduler->shouldSchedule());
        $this->assertSame(['deployed_at' => 1_000_000, 'deploy_id' => 'abc'], $this->readJson());
    }

    public function test_differing_deploy_id_restarts_warmup(): void
    {
        $this->writeFlag((string) json_encode(['deployed_at' => 100, 'deploy_id' => 'old']));
        $scheduler = $this->scheduler(300, 'new');

        $this->assertSame(300, $scheduler->remainingWarmup());
        $this->assertFalse($scheduler->shouldSchedule());
        $this->assertSame(['deployed_at' => 1_000_000, 'deploy_id' => 'new'], $this->readJson());
    }

    public function test_matching_deploy_id_keeps_flag(): void
    {
        $raw = (string) json_encode(['deployed_at' => 100, 'deploy_id' => 'same']);
        $this->writeFlag($raw);

        $this->assertTrue($this->scheduler(300, 'same')->shouldSchedule());
        $this->assertSame($raw, $this->readRaw());
    }

    public function test_null_deploy_id_ignores_flag_deploy_id(): void
    {
        $raw = (string) json_encode(['deployed_at' => 100, 'deploy_id' => 'x']);
        $this->writeFlag($raw);

        $this->assertTrue($this->scheduler(300)->shouldSchedule());
        $this->assertSame($raw, $this->readRaw());
    }

    #[DataProvider('corruptFlags')]
    public function test_corrupt_flag_is_rewritten(string $content): void
    {
        $this->writeFlag($content);
        $scheduler = $this->scheduler(300);

        $this->assertSame(300, $scheduler->remainingWarmup());
        $this->assertFalse($scheduler->shouldSchedule());
        $this->assertSame(['deployed_at' => 1_000_000, 'deploy_id' => null], $this->readJson());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function corruptFlags(): array
    {
        return [
            'invalid json' => ['{not json'],
            'non-integer deployed_at' => ['{"deployed_at":"x"}'],
            'empty array' => ['[]'],
            'non-string deploy_id' => ['{"deployed_at":5,"deploy_id":7}'],
            'empty file' => [''],
        ];
    }

    public function test_unwritable_location_reports_and_schedules(): void
    {
        Exceptions::fake();

        mkdir($this->dir, 0775, true);
        file_put_contents($this->dir.'/blocker', 'not a directory');

        $scheduler = new HealthCheckScheduler($this->dir.'/blocker/flag.json', 300, null, fn (): int => $this->now);

        $this->assertTrue($scheduler->shouldSchedule());
        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_zero_warmup_schedules_on_first_tick(): void
    {
        $this->assertTrue($this->scheduler(0)->shouldSchedule());
        $this->assertSame(['deployed_at' => 1_000_000, 'deploy_id' => null], $this->readJson());
    }

    public function test_remaining_warmup_does_not_write_flag(): void
    {
        $this->assertSame(300, $this->scheduler(300)->remainingWarmup());
        $this->assertFileDoesNotExist($this->flag);
    }

    public function test_active_warmup_is_null_without_a_flag(): void
    {
        $this->assertNull($this->scheduler(300)->activeWarmup());
        $this->assertFileDoesNotExist($this->flag);
    }

    public function test_active_warmup_is_null_for_a_foreign_deploy_flag(): void
    {
        $this->writeFlag((string) json_encode(['deployed_at' => $this->now - 10, 'deploy_id' => 'old']));

        $this->assertNull($this->scheduler(300, 'new')->activeWarmup());
    }

    public function test_active_warmup_is_null_for_a_corrupt_flag(): void
    {
        $this->writeFlag('not json');

        $this->assertNull($this->scheduler(300)->activeWarmup());
    }

    public function test_active_warmup_reports_remaining_seconds_of_a_current_flag(): void
    {
        $this->writeFlag((string) json_encode(['deployed_at' => $this->now - 100, 'deploy_id' => 'abc']));

        $this->assertSame(200, $this->scheduler(300, 'abc')->activeWarmup());
    }

    public function test_register_schedules_health_check_with_cron(): void
    {
        $this->writeFlag((string) json_encode(['deployed_at' => 0, 'deploy_id' => null]));
        $schedule = app(Schedule::class);

        $event = $this->scheduler(300)->register($schedule, '*/5 * * * *', false);

        $this->assertNotNull($event);
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertIsString($event->command);
        $this->assertStringContainsString('health:check', $event->command);
        $this->assertSame([$event], $schedule->events());
    }

    public function test_register_returns_null_during_warmup(): void
    {
        $schedule = app(Schedule::class);

        $this->assertNull($this->scheduler(300)->register($schedule, '* * * * *', true));
        $this->assertSame([], $schedule->events());
    }

    public function test_register_applies_on_one_server(): void
    {
        $this->writeFlag((string) json_encode(['deployed_at' => 0, 'deploy_id' => null]));

        $single = $this->scheduler(300)->register(app(Schedule::class), '* * * * *', true);
        $every = $this->scheduler(300)->register(app(Schedule::class), '* * * * *', false);

        $this->assertNotNull($single);
        $this->assertNotNull($every);
        $this->assertTrue($single->onOneServer);
        $this->assertFalse($every->onOneServer);
    }

    public function test_from_config_uses_defaults_and_storage_path(): void
    {
        $received = null;
        $config = new Repository(['observability' => [
            'deploy_id' => 'release-7',
            'health' => ['schedule' => ['warmup_seconds' => 60]],
        ]]);

        $scheduler = HealthCheckScheduler::fromConfig($config, function (string $path) use (&$received): string {
            $received = $path;

            return $this->flag;
        });

        $this->assertSame('app/observability/health-warmup.json', $received);
        $this->assertSame(60, $scheduler->remainingWarmup());

        $this->assertFalse($scheduler->shouldSchedule());
        $flag = $this->readJson();
        $this->assertSame('release-7', $flag['deploy_id'] ?? null);
    }

    public function test_from_config_defaults_warmup_and_ignores_empty_deploy_id(): void
    {
        $config = new Repository(['observability' => ['deploy_id' => '']]);

        $scheduler = HealthCheckScheduler::fromConfig($config, fn (string $path): string => $this->flag);

        $this->assertSame(300, $scheduler->remainingWarmup());
        $this->assertFalse($scheduler->shouldSchedule());
        $flag = $this->readJson();
        $this->assertArrayHasKey('deploy_id', $flag);
        $this->assertNull($flag['deploy_id']);
    }

    public function test_from_config_prefers_explicit_flag_path(): void
    {
        $called = false;
        $config = new Repository(['observability' => ['health' => ['schedule' => [
            'flag_path' => $this->flag,
            'warmup_seconds' => -5,
        ]]]]);

        $scheduler = HealthCheckScheduler::fromConfig($config, function (string $path) use (&$called): string {
            $called = true;

            return $path;
        });

        $this->assertTrue($scheduler->shouldSchedule());
        $this->assertFalse($called);
        $this->assertFileExists($this->flag);
    }

    private function scheduler(int $warmup, ?string $deployId = null): HealthCheckScheduler
    {
        return new HealthCheckScheduler($this->flag, $warmup, $deployId, fn (): int => $this->now);
    }

    private function writeFlag(string $content): void
    {
        if (! is_dir(dirname($this->flag))) {
            mkdir(dirname($this->flag), 0775, true);
        }

        file_put_contents($this->flag, $content);
    }

    private function readRaw(): string
    {
        $raw = file_get_contents($this->flag);
        $this->assertIsString($raw);

        return $raw;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function readJson(): array
    {
        $decoded = json_decode($this->readRaw(), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $child = $path.'/'.$item;
            is_dir($child) ? $this->removeDirectory($child) : unlink($child);
        }

        rmdir($path);
    }
}
