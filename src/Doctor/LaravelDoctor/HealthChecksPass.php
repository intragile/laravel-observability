<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Doctor\LaravelDoctor;

use IntrAgile\Observability\Doctor\DoctorOptions;
use IntrAgile\Observability\Doctor\DoctorRunner;
use IntrAgile\Observability\Doctor\Probes\HealthChecksProbe;
use IntrAgile\Observability\Doctor\Probes\SchedulingProbe;

/**
 * Health checks are registered and scheduled.
 *
 * The checks themselves are not run here, because some of them make network calls; that is what
 * `observability:doctor --live` is for.
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
        return [$this->checks->registration(), ...DoctorRunner::runProbe($this->scheduling, $options)];
    }
}
