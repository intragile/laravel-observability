<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Checks;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Carbon;
use Spatie\Backup\BackupDestination\BackupDestination;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;
use Throwable;

/**
 * Reports how long ago the last successful backup finished.
 *
 * The heartbeat is a Unix timestamp written by RecordBackupHeartbeat whenever spatie/laravel-backup
 * dispatches BackupWasSuccessful, so a normal run costs a single cache read instead of listing the
 * backup disk. When no heartbeat exists yet (fresh install, cache flushed, store renamed) the newest
 * archive on the configured backup disks is used once to seed it.
 *
 * spatie/laravel-backup is optional: this class only references its classes behind a class_exists()
 * guard, so it autoloads and runs without the package (and then fails until a heartbeat is recorded).
 */
final class BackupFreshnessCheck extends Check
{
    /**
     * Cache key shared with RecordBackupHeartbeat. Defined here rather than on the listener so that
     * reading it never autoloads a class that type-hints a spatie/laravel-backup event.
     */
    public const CACHE_KEY = 'observability:backup:last_success';

    private const CONFIG_STORE_KEY = 'observability.health.checks.backup_freshness.store';

    private int $warnAfterHours = 26;

    private int $failAfterHours = 48;

    private ?string $store = null;

    public function warnAfterHours(int $hours): static
    {
        $this->warnAfterHours = $hours;

        return $this;
    }

    public function failAfterHours(int $hours): static
    {
        $this->failAfterHours = $hours;

        return $this;
    }

    /**
     * Cache store to read the heartbeat from. The listener only knows the configured store
     * (`observability.health.checks.backup_freshness.store`), so prefer setting that config value;
     * an explicit store set here that differs from it will never see a heartbeat.
     */
    public function store(?string $store): static
    {
        $this->store = $store;

        return $this;
    }

    /**
     * Resolves the store in the same order for writer and reader: explicit name, then the configured
     * name, then the default store.
     */
    public static function resolveStore(?string $explicit): Repository
    {
        $configured = config(self::CONFIG_STORE_KEY);
        $name = $explicit ?? (is_string($configured) && $configured !== '' ? $configured : null);

        return app(CacheFactory::class)->store($name);
    }

    public function run(): Result
    {
        $cache = self::resolveStore($this->store);
        $timestamp = $this->normaliseTimestamp($cache->get(self::CACHE_KEY));

        if ($timestamp === null) {
            $timestamp = $this->seedFromDestinations();

            if ($timestamp !== null) {
                $cache->forever(self::CACHE_KEY, $timestamp);
            }
        }

        if ($timestamp === null) {
            return Result::make()
                ->shortSummary('none')
                ->meta(['last_success_at' => null, 'age_seconds' => null])
                ->failed('No successful backup has been recorded yet.');
        }

        // Compared in seconds so the thresholds are exact: 26h 1m is past a 26 hour limit.
        $ageSeconds = max(0, Carbon::now()->getTimestamp() - $timestamp);
        $ageHours = round($ageSeconds / 3600, 1);
        $lastSuccessAt = Carbon::createFromTimestamp($timestamp)->toIso8601String();

        $result = Result::make()
            ->shortSummary(number_format($ageHours, 1, '.', '').'h')
            ->meta(['last_success_at' => $lastSuccessAt, 'age_seconds' => $ageSeconds]);

        if ($ageSeconds > $this->failAfterHours * 3600) {
            return $result->failed("The last successful backup finished {$ageHours} hours ago, over the {$this->failAfterHours} hour limit.");
        }

        if ($ageSeconds > $this->warnAfterHours * 3600) {
            return $result->warning("The last successful backup finished {$ageHours} hours ago, over the {$this->warnAfterHours} hour warning threshold.");
        }

        return $result->ok("The last successful backup finished {$ageHours} hours ago.");
    }

    private function normaliseTimestamp(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * Newest backup date across all configured destination disks, or null when spatie/laravel-backup
     * is missing, nothing is configured, no archive exists or every disk is unreachable.
     */
    private function seedFromDestinations(): ?int
    {
        if (! $this->backupPackageInstalled(BackupDestination::class)) {
            return null;
        }

        $backupName = config('backup.backup.name');

        if (! is_string($backupName) || $backupName === '') {
            return null;
        }

        $disks = config('backup.backup.destination.disks');
        $newest = null;

        foreach (is_array($disks) ? $disks : [] as $disk) {
            if (! is_string($disk)) {
                continue;
            }

            try {
                $timestamp = BackupDestination::create($disk, $backupName)->newestBackup()?->date()->getTimestamp();
            } catch (Throwable) {
                continue;
            }

            if ($timestamp !== null && ($newest === null || $timestamp > $newest)) {
                $newest = $timestamp;
            }
        }

        return $newest;
    }

    /**
     * Takes the class name as an argument so static analysis, which sees the dev dependency, does not
     * fold the guard into a constant; at runtime the package may well be absent.
     */
    private function backupPackageInstalled(string $class): bool
    {
        return class_exists($class);
    }
}
