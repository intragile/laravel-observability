<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Doctor\Probes;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use IntrAgile\Observability\Doctor\DoctorOptions;
use IntrAgile\Observability\Doctor\Probe;
use IntrAgile\Observability\Readiness\ReadinessEntry;
use IntrAgile\Observability\Readiness\ReadinessStatus;

/**
 * Checks that the commands the package depends on are actually on the schedule.
 *
 * Registered health checks only produce stored results and notifications when health:check runs,
 * and recorded errors only reach Slack when the digest runs. Either missing from the schedule fails
 * silently, which is exactly what this probe is for.
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
        $health = $this->find($events, self::HEALTH_COMMAND);

        $entries[] = $health === null
            ? new ReadinessEntry('Health check schedule', ReadinessStatus::Warning, self::HEALTH_COMMAND.' is not scheduled, so health '
                .'results and notifications go stale. Add Schedule::command(RunHealthChecksCommand::class)->everyMinute().')
            : new ReadinessEntry('Health check schedule', ReadinessStatus::Ok, self::HEALTH_COMMAND." is scheduled ({$health->expression}).");

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
