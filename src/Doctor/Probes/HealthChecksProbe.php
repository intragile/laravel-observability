<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Doctor\Probes;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Date;
use IntrAgile\Observability\Checks\BroadcastCheck;
use IntrAgile\Observability\Doctor\DoctorOptions;
use IntrAgile\Observability\Doctor\Probe;
use IntrAgile\Observability\Readiness\ReadinessEntry;
use IntrAgile\Observability\Readiness\ReadinessStatus;
use IntrAgile\Observability\Support\SqlMessageRedactor;
use Spatie\Health\Checks\Check;
use Spatie\Health\Enums\Status;
use Spatie\Health\Health;
use Spatie\Health\ResultStores\ResultStore;
use Spatie\Health\ResultStores\StoredCheckResults\StoredCheckResult;
use Throwable;

/**
 * Runs every registered health check once and times it.
 *
 * spatie/laravel-health turns a throwing check into a "crashed" result that only shows up after the
 * next scheduled run; running them here surfaces the crash, its exception class and slow checks at
 * deploy time. Results are not stored and no notification is sent.
 *
 * Live because checks may call out to other services (the broadcast check opens a WebSocket).
 *
 * storedResults() is the offline counterpart: it reads the latest run that `health:check` stored and
 * never runs a check itself.
 */
final class HealthChecksProbe implements Probe
{
    private const STORED_COMPONENT = 'Stored health results';

    private const DEFAULT_STALE_MINUTES = 10;

    /**
     * @param  (Closure(): ResultStore)|null  $resultStore  resolved lazily so a misconfigured store is reported, not thrown; defaults to the container binding
     * @param  Repository|null  $config  defaults to the container's config repository
     */
    public function __construct(
        private readonly Health $health,
        private readonly ?Closure $resultStore = null,
        private readonly ?Repository $config = null,
    ) {}

    public function name(): string
    {
        return 'Health checks probe';
    }

    public function live(): bool
    {
        return true;
    }

    /**
     * The registered checks, without running any of them.
     */
    public function registration(): ReadinessEntry
    {
        $labels = $this->health->registeredChecks()->map(static fn (Check $check): string => $check->getLabel())->all();

        if ($labels === []) {
            return new ReadinessEntry('Health checks', ReadinessStatus::Warning, 'No health checks are registered with spatie/laravel-health.');
        }

        return new ReadinessEntry('Health checks', ReadinessStatus::Ok, count($labels).' registered: '.implode(', ', $labels).'.');
    }

    /**
     * The latest run stored by `health:check`, without running any check.
     *
     * Reports a missing or stale run, failed and crashed checks, and warnings. A store that cannot be
     * read is a warning rather than a failure: the checks themselves may be fine. Skipped checks are
     * not counted as errors.
     */
    public function storedResults(): ReadinessEntry
    {
        try {
            $results = $this->resolveResultStore()->latestResults();
        } catch (Throwable $exception) {
            return new ReadinessEntry(self::STORED_COMPONENT, ReadinessStatus::Warning, 'Could not read the stored results: '
                .$exception::class.': '.SqlMessageRedactor::redact($exception->getMessage()));
        }

        if ($results === null) {
            return new ReadinessEntry(self::STORED_COMPONENT, ReadinessStatus::Warning, 'No stored results yet: health:check has not run (or is still in its warmup).');
        }

        $minutes = intdiv(max(0, Date::now()->getTimestamp() - $results->finishedAt->getTimestamp()), 60);
        $stale = $this->staleMinutes();

        $staleness = "Last run {$minutes} minutes ago, longer than the {$stale}-minute limit: is health:check scheduled?";
        $count = count($results->storedCheckResults);
        $failing = $results->storedCheckResults->filter(static fn (StoredCheckResult $result): bool => in_array($result->status, [Status::failed()->value, Status::crashed()->value], true));

        // A failure outranks staleness: an old run that already failed must not read as a warning.
        if ($failing->isNotEmpty()) {
            return new ReadinessEntry(self::STORED_COMPONENT, ReadinessStatus::Failed, $failing->count()." of {$count} checks failing: "
                .self::describe($failing->all()).'. '.($minutes > $stale ? $staleness : "Last run {$minutes} minutes ago."));
        }

        if ($minutes > $stale) {
            return new ReadinessEntry(self::STORED_COMPONENT, ReadinessStatus::Warning, $staleness);
        }

        $warning = $results->storedCheckResults->filter(static fn (StoredCheckResult $result): bool => $result->status === Status::warning()->value);

        if ($warning->isNotEmpty()) {
            return new ReadinessEntry(self::STORED_COMPONENT, ReadinessStatus::Warning, $warning->count().' checks warning: '
                .self::describe($warning->all()).". Last run {$minutes} minutes ago.");
        }

        return new ReadinessEntry(self::STORED_COMPONENT, ReadinessStatus::Ok, "{$count} checks ok, last run {$minutes} minutes ago.");
    }

    public function run(DoctorOptions $options): array
    {
        $checks = $this->health->registeredChecks();

        if ($checks->isEmpty()) {
            return [$this->registration()];
        }

        $entries = [];

        foreach ($checks as $check) {
            $entries[] = $this->runCheck($check, $options->timeout);
        }

        return $entries;
    }

    private function resolveResultStore(): ResultStore
    {
        if ($this->resultStore !== null) {
            return ($this->resultStore)();
        }

        return Container::getInstance()->make(ResultStore::class);
    }

    private function staleMinutes(): int
    {
        $config = $this->config ?? Container::getInstance()->make('config');
        $value = $config->get('observability.health.stored_results.stale_minutes', self::DEFAULT_STALE_MINUTES);

        return is_numeric($value) ? (int) $value : self::DEFAULT_STALE_MINUTES;
    }

    /**
     * @param  array<array-key, StoredCheckResult>  $results
     */
    private static function describe(array $results): string
    {
        return implode(', ', array_map(
            static fn (StoredCheckResult $result): string => ($result->label !== '' ? $result->label : $result->name)." ({$result->status})",
            $results,
        ));
    }

    private function runCheck(Check $check, float $timeout): ReadinessEntry
    {
        $component = 'Health: '.$check->getLabel();

        if (! $check->shouldRun()) {
            return new ReadinessEntry($component, ReadinessStatus::Skipped, 'Not run: its run condition is false.');
        }

        // The broadcast check waits on the network; bound it by the doctor timeout. The registered
        // instance is left untouched.
        if ($check instanceof BroadcastCheck) {
            $check = (clone $check)->timeout($timeout);
        }

        $started = hrtime(true);

        try {
            $result = $check->run();
        } catch (Throwable $exception) {
            return new ReadinessEntry($component, ReadinessStatus::Failed, 'The check threw '.$exception::class.': '
                .SqlMessageRedactor::redact($exception->getMessage()));
        }

        $milliseconds = (int) round((hrtime(true) - $started) / 1e6);

        $status = match (true) {
            $result->status->equals(Status::ok()) => ReadinessStatus::Ok,
            $result->status->equals(Status::warning()) => ReadinessStatus::Warning,
            $result->status->equals(Status::skipped()) => ReadinessStatus::Skipped,
            default => ReadinessStatus::Failed,
        };

        $message = trim($result->getNotificationMessage());
        $message = $message !== '' ? $message : trim($result->getShortSummary());
        $message = ($message !== '' ? $message : ucfirst($result->status->label).'.')." ({$milliseconds} ms)";

        if ($status === ReadinessStatus::Ok && $milliseconds > $timeout * 1000) {
            return new ReadinessEntry($component, ReadinessStatus::Warning, "Took {$milliseconds} ms, slower than the {$timeout} s timeout. {$message}");
        }

        return new ReadinessEntry($component, $status, $message);
    }
}
