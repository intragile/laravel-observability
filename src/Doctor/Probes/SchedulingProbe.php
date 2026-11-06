<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Doctor\Probes;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use IntrAgile\Observability\Doctor\DoctorOptions;
use IntrAgile\Observability\Doctor\Probe;
use IntrAgile\Observability\Health\HealthCheckScheduler;
use IntrAgile\Observability\Readiness\ReadinessEntry;
use IntrAgile\Observability\Readiness\ReadinessStatus;

/**
 * Checks that the commands the package depends on are actually on the schedule.
 *
 * Registered health checks only produce stored results and notifications when health:check runs,
 * and recorded errors only reach Slack when the digest runs. Either missing from the schedule fails
 * silently, which is exactly what this probe is for. When the package schedules health:check itself
 * it holds the command back for a warmup window after each deploy; that is intentional, so it is
 * reported as Skipped rather than as a missing schedule, but only while a window started by a real
 * scheduler tick for the current deploy is running. Without such a window the scheduler has not run.
 */
final class SchedulingProbe implements Probe
{
    private const HEALTH_COMMAND = 'health:check';

    private const DIGEST_COMMAND = 'observability:error-digest';

    public function __construct(
        private readonly Container $app,
        private readonly Repository $config,
    ) {}

    public function name(): string
    {
        return 'Scheduling probe';
    }

    public function live(): bool
    {
        return false;
    }

    public function run(DoctorOptions $options): array
    {
        $events = $this->app->make(Schedule::class)->events();

        $entries = [];
        $entries[] = $this->healthEntry($this->find($events, self::HEALTH_COMMAND));

        if (! filter_var($this->config->get('observability.error_digest.enabled'), FILTER_VALIDATE_BOOLEAN)) {
            $entries[] = new ReadinessEntry('Error digest schedule', ReadinessStatus::Skipped, 'The error digest is disabled.');

            return $entries;
        }

        $digest = $this->find($events, self::DIGEST_COMMAND);

        $entries[] = $digest === null
            ? new ReadinessEntry('Error digest schedule', ReadinessStatus::Warning, self::DIGEST_COMMAND.' is not scheduled, so '
                .'recorded errors never reach Slack. Is the scheduler (schedule:run or schedule:work) running this application?')
            : new ReadinessEntry('Error digest schedule', ReadinessStatus::Ok, self::DIGEST_COMMAND." is scheduled ({$digest->expression}).");

        return $entries;
    }

    private function healthEntry(?Event $health): ReadinessEntry
    {
        if ($health !== null) {
            return new ReadinessEntry('Health check schedule', ReadinessStatus::Ok, self::HEALTH_COMMAND." is scheduled ({$health->expression}).");
        }

        if (filter_var($this->config->get('observability.health.schedule.enabled', false), FILTER_VALIDATE_BOOLEAN)) {
            $remaining = HealthCheckScheduler::fromConfig($this->config, storage_path(...))->activeWarmup();

            if ($remaining === null) {
                return new ReadinessEntry('Health check schedule', ReadinessStatus::Warning, self::HEALTH_COMMAND.' is not scheduled '
                    .'and no scheduler tick has started its warmup since the deploy, so health results and notifications go stale. '
                    .'Is the scheduler (schedule:run or schedule:work) running this application?');
            }

            if ($remaining > 0) {
                return new ReadinessEntry('Health check schedule', ReadinessStatus::Skipped, self::HEALTH_COMMAND
                    ." is held back for {$remaining} more seconds after the deploy (warmup).");
            }
        }

        return new ReadinessEntry('Health check schedule', ReadinessStatus::Warning, self::HEALTH_COMMAND.' is not scheduled, so health '
            .'results and notifications go stale. Add Schedule::command(RunHealthChecksCommand::class)->everyMinute().');
    }

    /**
     * Scheduled commands are stored as a shell string such as "'php' 'artisan' health:check", so
     * the command name is matched as a whole word within it.
     *
     * @param  array<array-key, Event>  $events
     */
    private function find(array $events, string $command): ?Event
    {
        $pattern = '/(^|[\s\'"])'.preg_quote($command, '/').'([\s\'"]|$)/';

        foreach ($events as $event) {
            if (is_string($event->command) && preg_match($pattern, $event->command) === 1) {
                return $event;
            }
        }

        return null;
    }
}
