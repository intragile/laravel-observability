<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Doctor\LaravelDoctor;

use IntrAgile\Observability\Doctor\DoctorOptions;
use IntrAgile\Observability\Doctor\DoctorRunner;
use IntrAgile\Observability\Doctor\Probes\ErrorDigestProbe;

/**
 * The error digest has a webhook and its report table; no message is sent from here.
 */
final class ErrorDigestIsDeliverable extends ObservabilityDiagnostic
{
    public function __construct(private readonly ErrorDigestProbe $probe)
    {
        parent::__construct();
    }

    protected function entries(DoctorOptions $options): array
    {
        return DoctorRunner::runProbe($this->probe, $options);
    }
}
