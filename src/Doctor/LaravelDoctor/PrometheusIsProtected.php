<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Doctor\LaravelDoctor;

use IntrAgile\Observability\Doctor\DoctorOptions;
use IntrAgile\Observability\Doctor\DoctorRunner;
use IntrAgile\Observability\Doctor\Probes\PrometheusProbe;

/**
 * The metrics endpoint rejects unauthenticated requests and serves authenticated ones, checked with in-process requests.
 */
final class PrometheusIsProtected extends ObservabilityDiagnostic
{
    public function __construct(private readonly PrometheusProbe $probe)
    {
        parent::__construct();
    }

    protected function entries(DoctorOptions $options): array
    {
        return DoctorRunner::runProbe($this->probe, $options);
    }
}
