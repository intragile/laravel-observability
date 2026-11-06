<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Readiness;

/**
 * Verdict for one component of the observability setup.
 *
 * The static inspection only ever reports Ok, Skipped or Warning: it reads configuration and
 * installed packages, so it can tell that something is misconfigured but not that it is broken.
 * Failed is reserved for live probes that actually exercised the component.
 */
enum ReadinessStatus: string
{
    case Ok = 'ok';
    case Skipped = 'skipped';
    case Warning = 'warning';
    case Failed = 'failed';
}
