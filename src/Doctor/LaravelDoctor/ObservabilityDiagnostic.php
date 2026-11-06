<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Doctor\LaravelDoctor;

use IntrAgile\Observability\Doctor\DoctorOptions;
use IntrAgile\Observability\Doctor\DoctorReport;
use IntrAgile\Observability\Readiness\ReadinessEntry;
use IntrAgile\Observability\Readiness\ReadinessStatus;
use Laravel\Doctor\Diagnostic;
use Laravel\Doctor\Doctor;
use Laravel\Doctor\Results\DiagnosticResult;

/**
 * Shows the observability probes inside laravel/doctor, in the "observability" group.
 *
 * Only in-process probes run here: laravel/doctor is commonly run on every deploy and in CI, where
 * an unexpected WebSocket connection or Slack message would be a surprise. The live probes stay
 * behind `observability:doctor --live`.
 *
 * laravel/doctor is a 0.x package, so this directory is the only place that references it.
 */
abstract class ObservabilityDiagnostic extends Diagnostic
{
    public const DIAGNOSTICS = [
        LoggingIsStructured::class,
        PrometheusIsProtected::class,
        HealthChecksPass::class,
        ErrorDigestIsDeliverable::class,
    ];

    public string $group = 'observability';

    /**
     * Registers the diagnostics with a resolved laravel/doctor instance.
     */
    public static function register(object $doctor): void
    {
        if ($doctor instanceof Doctor) {
            $doctor->diagnostics(self::DIAGNOSTICS);
        }
    }

    /**
     * @return list<ReadinessEntry>
     */
    abstract protected function entries(DoctorOptions $options): array;

    public function check(): DiagnosticResult
    {
        $entries = $this->entries(DoctorOptions::staticOnly());

        if ($entries === []) {
            return DiagnosticResult::skip('Nothing to check.', $this->code('skip'));
        }

        $worst = (new DoctorReport(array_map(
            static fn (ReadinessEntry $entry): array => ['entry' => $entry, 'duration_ms' => null],
            $entries,
        )))->worst();

        $summary = implode(' ', array_map(
            static fn (ReadinessEntry $entry): string => "{$entry->component}: {$entry->message}",
            array_values(array_filter($entries, static fn (ReadinessEntry $entry): bool => $entry->status === $worst)),
        ));

        $result = match ($worst) {
            ReadinessStatus::Ok => DiagnosticResult::pass($summary, $this->code('pass')),
            ReadinessStatus::Skipped => DiagnosticResult::skip($summary, $this->code('skip')),
            ReadinessStatus::Warning => DiagnosticResult::warn($summary, $this->code('warn')),
            ReadinessStatus::Failed => DiagnosticResult::fail($summary, $this->code('fail')),
        };

        if (count($entries) > 1) {
            $result->withDetails(implode(PHP_EOL, array_map(
                static fn (ReadinessEntry $entry): string => "- [{$entry->status->value}] {$entry->component}: {$entry->message}",
                $entries,
            )));
        }

        if ($worst === ReadinessStatus::Warning || $worst === ReadinessStatus::Failed) {
            $result->suggest('Run `php artisan observability:doctor --live` to probe the whole chain, including network calls.');
        }

        return $result;
    }
}
