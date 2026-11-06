<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Doctor\LaravelDoctor;

use IntrAgile\Observability\Doctor\DoctorOptions;
use IntrAgile\Observability\Doctor\DoctorRunner;
use IntrAgile\Observability\Doctor\Probes\LoggingProbe;

/**
 * Structured, Loki-ready logging, checked by formatting a synthetic record with an SQL literal.
 */
final class LoggingIsStructured extends ObservabilityDiagnostic
{
    public function __construct(private readonly LoggingProbe $probe)
    {
        parent::__construct();
    }

    protected function entries(DoctorOptions $options): array
    {
        return DoctorRunner::runProbe($this->probe, $options);
    }
}
