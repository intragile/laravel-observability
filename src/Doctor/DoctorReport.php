<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Doctor;

use IntrAgile\Observability\Readiness\ReadinessEntry;
use IntrAgile\Observability\Readiness\ReadinessStatus;

/**
 * The outcome of a doctor run: every entry in order, with how long producing it took.
 */
final readonly class DoctorReport
{
    /**
     * @param  list<array{entry: ReadinessEntry, duration_ms: float|null}>  $rows  duration is null for
     *                                                                             entries that were not timed
     */
    public function __construct(private array $rows) {}

    /**
     * @return list<ReadinessEntry>
     */
    public function entries(): array
    {
        return array_map(static fn (array $row): ReadinessEntry => $row['entry'], $this->rows);
    }

    /**
     * @return list<float|null>
     */
    public function durations(): array
    {
        return array_map(static fn (array $row): ?float => $row['duration_ms'], $this->rows);
    }

    /**
     * The most severe status in the report.
     *
     * Skipped ranks below Ok: a component that is not in use must not hide that the rest passed,
     * and a report is only "skipped" when nothing at all was checked.
     */
    public function worst(): ReadinessStatus
    {
        $worst = null;

        foreach ($this->rows as $row) {
            $status = $row['entry']->status;

            if ($worst === null || self::severity($status) > self::severity($worst)) {
                $worst = $status;
            }
        }

        return $worst ?? ReadinessStatus::Ok;
    }

    /**
     * @return array{entries: list<array{component: string, status: string, message: string, duration_ms: float|null}>, worst: string}
     */
    public function toArray(): array
    {
        return [
            'entries' => array_map(static fn (array $row): array => [
                'component' => $row['entry']->component,
                'status' => $row['entry']->status->value,
                'message' => $row['entry']->message,
                'duration_ms' => $row['duration_ms'] === null ? null : round($row['duration_ms'], 2),
            ], $this->rows),
            'worst' => $this->worst()->value,
        ];
    }

    public static function severity(ReadinessStatus $status): int
    {
        return match ($status) {
            ReadinessStatus::Skipped => 0,
            ReadinessStatus::Ok => 1,
            ReadinessStatus::Warning => 2,
            ReadinessStatus::Failed => 3,
        };
    }
}
