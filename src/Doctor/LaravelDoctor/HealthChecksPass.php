<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Doctor\LaravelDoctor;

use IntrAgile\Observability\Doctor\DoctorOptions;
use IntrAgile\Observability\Doctor\DoctorRunner;
use IntrAgile\Observability\Doctor\Probes\HealthChecksProbe;
use IntrAgile\Observability\Doctor\Probes\SchedulingProbe;

/**
 * Health checks are registered, their latest stored run is recent and passing, and they are scheduled.
 *
 * The checks themselves are never run here, because some of them make network calls; the stored
 * results are only read from the result store that `health:check` writes to. Running the checks is
 * what `observability:doctor --live` is for.
 */
final class HealthChecksPass extends ObservabilityDiagnostic
{
    public function __construct(
        private readonly HealthChecksProbe $checks,
        private readonly SchedulingProbe $scheduling,
    ) {
        parent::__construct();
    }

    protected function entries(DoctorOptions $options): array
    {
        return [
            $this->checks->registration(),
            $this->checks->storedResults(),
            ...DoctorRunner::runProbe($this->scheduling, $options),
        ];
    }
}
