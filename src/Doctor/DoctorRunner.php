<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Doctor;

use IntrAgile\Observability\Readiness\Inspector;
use IntrAgile\Observability\Readiness\ReadinessEntry;
use IntrAgile\Observability\Readiness\ReadinessStatus;
use IntrAgile\Observability\Support\SqlMessageRedactor;
use Throwable;

/**
 * Runs the static readiness inspection followed by the probes.
 *
 * A probe that throws becomes a Failed entry and the run continues: a doctor that dies on the first
 * broken component would hide every problem behind it.
 */
final class DoctorRunner
{
    /**
     * @param  list<Probe>  $probes  in the order they should run and be reported
     */
    public function __construct(
        private readonly Inspector $inspector,
        private readonly array $probes,
    ) {}

    /**
     * @return list<Probe>
     */
    public function probes(): array
    {
        return $this->probes;
    }

    public function run(DoctorOptions $options): DoctorReport
    {
        $rows = array_map(
            static fn (ReadinessEntry $entry): array => ['entry' => $entry, 'duration_ms' => null],
            $this->inspector->inspect(),
        );

        foreach ($this->probes as $probe) {
            if ($probe->live() && ! $options->live) {
                continue;
            }

            [$entries, $durationMs] = self::timed($probe, $options);

            // A probe reports its steps together, so each of its entries carries the probe's time.
            foreach ($entries as $entry) {
                $rows[] = ['entry' => $entry, 'duration_ms' => $durationMs];
            }
        }

        return new DoctorReport($rows);
    }

    /**
     * Runs one probe, turning an exception into a Failed entry.
     *
     * @return list<ReadinessEntry>
     */
    public static function runProbe(Probe $probe, DoctorOptions $options): array
    {
        return self::timed($probe, $options)[0];
    }

    /**
     * @return array{list<ReadinessEntry>, float}
     */
    private static function timed(Probe $probe, DoctorOptions $options): array
    {
        $started = hrtime(true);

        try {
            $entries = $probe->run($options);
        } catch (Throwable $exception) {
            // Exception messages can carry interpolated SQL; the report may end up in CI logs.
            $entries = [new ReadinessEntry(
                $probe->name(),
                ReadinessStatus::Failed,
                'The probe threw '.$exception::class.': '.SqlMessageRedactor::redact($exception->getMessage()),
            )];
        }

        return [$entries, (hrtime(true) - $started) / 1e6];
    }
}
