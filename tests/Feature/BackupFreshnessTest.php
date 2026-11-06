<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use IntrAgile\Observability\Backup\RecordBackupHeartbeat;
use IntrAgile\Observability\Checks\BackupFreshnessCheck;
use IntrAgile\Observability\Tests\TestCase;
use Spatie\Backup\BackupDestination\BackupDestination;
use Spatie\Backup\Events\BackupWasSuccessful;
use Spatie\Backup\Tasks\Backup\BackupJob;
use Spatie\Health\Checks\Result;
use Spatie\Health\Enums\Status;

final class BackupFreshnessTest extends TestCase
{
    private string $backupRoot = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(BackupWasSuccessful::class) || ! class_exists(BackupDestination::class)) {
            $this->markTestSkipped('spatie/laravel-backup is not installed.');
        }

        $this->backupRoot = sys_get_temp_dir().'/obs-'.bin2hex(random_bytes(6));
        mkdir($this->backupRoot, 0777, true);

        config()->set('filesystems.disks.backup_test', ['driver' => 'local', 'root' => $this->backupRoot]);
        config()->set('backup.backup.name', 'obs-test');
        config()->set('backup.backup.destination.disks', ['backup_test']);
        config()->set('cache.default', 'array');

        $this->freezeTime();
    }

    protected function tearDown(): void
    {
        if ($this->backupRoot !== '' && is_dir($this->backupRoot)) {
            $this->removeDirectory($this->backupRoot);
        }

        parent::tearDown();
    }

    public function test_heartbeat_is_recorded_after_successful_backup(): void
    {
        (new RecordBackupHeartbeat)->handle(new BackupWasSuccessful('backup_test', 'obs-test'));

        $this->assertSame(now()->getTimestamp(), Cache::get(BackupFreshnessCheck::CACHE_KEY));
    }

    public function test_25_hours_is_ok(): void
    {
        $this->recordHeartbeatSecondsAgo(25 * 3600);

        $this->assertSame(Status::ok()->value, $this->runCheck()->status->value);
    }

    public function test_26_hours_1_minute_warns(): void
    {
        $this->recordHeartbeatSecondsAgo(26 * 3600 + 60);

        $this->assertSame(Status::warning()->value, $this->runCheck()->status->value);
    }

    public function test_exactly_26_hours_is_still_ok(): void
    {
        $this->recordHeartbeatSecondsAgo(26 * 3600);

        $this->assertSame(Status::ok()->value, $this->runCheck()->status->value);
    }

    public function test_48_hours_1_minute_fails(): void
    {
        $this->recordHeartbeatSecondsAgo(48 * 3600 + 60);

        $this->assertSame(Status::failed()->value, $this->runCheck()->status->value);
    }

    public function test_custom_thresholds_are_honoured(): void
    {
        $this->recordHeartbeatSecondsAgo(3 * 3600);

        $result = BackupFreshnessCheck::new()->warnAfterHours(1)->failAfterHours(2)->run();

        $this->assertSame(Status::failed()->value, $result->status->value);
    }

    public function test_missing_heartbeat_is_seeded_from_newest_backup(): void
    {
        $this->putBackupSecondsAgo(40 * 3600);
        $this->putBackupSecondsAgo(3 * 3600);

        $result = $this->runCheck();

        $this->assertSame(Status::ok()->value, $result->status->value);
        $this->assertSame(now()->subSeconds(3 * 3600)->getTimestamp(), Cache::get(BackupFreshnessCheck::CACHE_KEY));
    }

    public function test_seeded_backup_is_evaluated_against_thresholds(): void
    {
        $this->putBackupSecondsAgo(30 * 3600);

        $this->assertSame(Status::warning()->value, $this->runCheck()->status->value);
        $this->assertSame(now()->subSeconds(30 * 3600)->getTimestamp(), Cache::get(BackupFreshnessCheck::CACHE_KEY));
    }

    public function test_stale_backup_on_disk_still_fails(): void
    {
        $this->putBackupSecondsAgo(50 * 3600);

        $this->assertSame(Status::failed()->value, $this->runCheck()->status->value);
    }

    public function test_missing_heartbeat_without_backup_fails(): void
    {
        $result = $this->runCheck();

        $this->assertSame(Status::failed()->value, $result->status->value);
        $this->assertNull(Cache::get(BackupFreshnessCheck::CACHE_KEY));
    }

    public function test_unreachable_backup_disk_fails_without_throwing(): void
    {
        config()->set('backup.backup.destination.disks', ['does_not_exist']);

        $this->assertSame(Status::failed()->value, $this->runCheck()->status->value);
    }

    public function test_listener_and_check_share_configured_store(): void
    {
        config()->set('cache.stores.backup_heartbeat', ['driver' => 'array']);
        config()->set('observability.health.checks.backup_freshness.store', 'backup_heartbeat');

        (new RecordBackupHeartbeat)->handle(new BackupWasSuccessful('backup_test', 'obs-test'));

        $this->assertNull(Cache::store('array')->get(BackupFreshnessCheck::CACHE_KEY));
        $this->assertSame(now()->getTimestamp(), Cache::store('backup_heartbeat')->get(BackupFreshnessCheck::CACHE_KEY));
        $this->assertSame(Status::ok()->value, $this->runCheck()->status->value);
    }

    private function runCheck(): Result
    {
        return BackupFreshnessCheck::new()->run();
    }

    private function recordHeartbeatSecondsAgo(int $seconds): void
    {
        Cache::forever(BackupFreshnessCheck::CACHE_KEY, now()->getTimestamp() - $seconds);
    }

    private function putBackupSecondsAgo(int $seconds): void
    {
        $directory = $this->backupRoot.'/obs-test';

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $path = $directory.'/'.now()->subSeconds($seconds)->format(BackupJob::FILENAME_FORMAT);
        file_put_contents($path, 'PK');
    }

    private function removeDirectory(string $directory): void
    {
        $entries = scandir($directory);

        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;
            is_dir($path) && ! is_link($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($directory);
    }
}
