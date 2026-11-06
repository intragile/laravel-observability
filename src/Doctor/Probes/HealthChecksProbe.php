<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Doctor\Probes;

use IntrAgile\Observability\Checks\BroadcastCheck;
use IntrAgile\Observability\Doctor\DoctorOptions;
use IntrAgile\Observability\Doctor\Probe;
use IntrAgile\Observability\Readiness\ReadinessEntry;
use IntrAgile\Observability\Readiness\ReadinessStatus;
use IntrAgile\Observability\Support\SqlMessageRedactor;
use Spatie\Health\Checks\Check;
use Spatie\Health\Enums\Status;
use Spatie\Health\Health;
use Throwable;

/**
 * Runs every registered health check once and times it.
 *
 * spatie/laravel-health turns a throwing check into a "crashed" result that only shows up after the
 * next scheduled run; running them here surfaces the crash, its exception class and slow checks at
 * deploy time. Results are not stored and no notification is sent.
 *
 * Live because checks may call out to other services (the broadcast check opens a WebSocket).
 */
final class HealthChecksProbe implements Probe
{
    public function __construct(private readonly Health $health) {}

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
