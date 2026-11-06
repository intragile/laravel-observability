<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Console;

use Illuminate\Console\Command;
use IntrAgile\Observability\Doctor\DoctorOptions;
use IntrAgile\Observability\Doctor\DoctorReport;
use IntrAgile\Observability\Doctor\DoctorRunner;
use IntrAgile\Observability\Readiness\ReadinessStatus;

/**
 * Exercises the observability chain end to end and reports what actually works.
 *
 * Without options only in-process probes run, so the command is safe in any environment. --live
 * adds the probes that make network calls; --send-test posts a real Slack message. The exit code
 * makes the command usable as a deploy gate.
 */
final class DoctorCommand extends Command
{
    /** @var string */
    protected $signature = 'observability:doctor
        {--live : Also run the probes that make network calls: every health check and the broadcast round trip}
        {--send-test : Post a test message to the error digest webhook}
        {--json : Print the report as JSON}
        {--strict : Exit with a non-zero status on warnings as well}
        {--timeout=5 : Seconds allowed for each network call; slower health checks are reported as warnings}';

    /** @var string */
    protected $description = 'Probe the observability setup end to end';

    public function handle(DoctorRunner $runner): int
    {
        $timeout = $this->option('timeout');

        if (! is_numeric($timeout) || (float) $timeout <= 0) {
            $this->error('The --timeout option must be a positive number of seconds.');

            return self::INVALID;
        }

        $report = $runner->run(new DoctorOptions(
            live: (bool) $this->option('live'),
            sendTest: (bool) $this->option('send-test'),
            timeout: (float) $timeout,
        ));

        if ($this->option('json')) {
            $this->line((string) json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->render($report);
        }

        return match ($report->worst()) {
            ReadinessStatus::Failed => self::FAILURE,
            ReadinessStatus::Warning => $this->option('strict') ? self::FAILURE : self::SUCCESS,
            default => self::SUCCESS,
        };
    }

    private function render(DoctorReport $report): void
    {
        $durations = $report->durations();
        $rows = [];

        foreach ($report->entries() as $index => $entry) {
            $duration = $durations[$index] ?? null;
            $rows[] = [
                $entry->component,
                $this->status($entry->status),
                $entry->message,
                $duration === null ? '' : number_format($duration, 0).' ms',
            ];
        }

        $this->table(['Component', 'Status', 'Details', 'Time'], $rows);

        if (! $this->option('live')) {
            $this->line('Network probes were not run; add <comment>--live</comment> to run every health check and the broadcast round trip.');
        }

        $this->line('Worst status: '.$this->status($report->worst()));
    }

    private function status(ReadinessStatus $status): string
    {
        return match ($status) {
            ReadinessStatus::Ok => '<info>ok</info>',
            ReadinessStatus::Skipped => 'skipped',
            ReadinessStatus::Warning => '<comment>warning</comment>',
            ReadinessStatus::Failed => '<error>failed</error>',
        };
    }
}
