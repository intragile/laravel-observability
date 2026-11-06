<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Backup;

use Illuminate\Support\Carbon;
use IntrAgile\Observability\Checks\BackupFreshnessCheck;
use Spatie\Backup\Events\BackupWasSuccessful;

/**
 * Records the time of every successful backup for BackupFreshnessCheck.
 *
 * The timestamp is stored without expiry: a TTL would turn a long but healthy gap into a false
 * "no backup recorded" alarm. It is written to the store configured at
 * `observability.health.checks.backup_freshness.store` (default store when unset); a store passed
 * only to BackupFreshnessCheck::store() is not visible here, so configure the store in config.
 */
final class RecordBackupHeartbeat
{
    public function handle(BackupWasSuccessful $event): void
    {
        BackupFreshnessCheck::resolveStore(null)->forever(BackupFreshnessCheck::CACHE_KEY, Carbon::now()->getTimestamp());
    }
}
