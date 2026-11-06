<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Doctor;

use IntrAgile\Observability\Readiness\ReadinessEntry;

/**
 * One step of the doctor run that exercises part of the observability chain.
 *
 * Unlike the static readiness inspection, a probe pushes something through the real component (a
 * log record through the configured formatter, a request through the HTTP kernel) and reports what
 * actually came out.
 */
interface Probe
{
    /**
     * Human-readable name, used as the component when the probe itself throws.
     */
    public function name(): string;

    /**
     * Whether the probe makes network calls or has effects outside the process. Such probes only
     * run when the doctor is invoked with --live, so the default run is safe anywhere.
     */
    public function live(): bool;

    /**
     * @return list<ReadinessEntry>
     */
    public function run(DoctorOptions $options): array;
}
