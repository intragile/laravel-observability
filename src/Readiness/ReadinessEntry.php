<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Readiness;

/**
 * One row of the readiness report: a component, its verdict and a human-readable reason.
 */
final readonly class ReadinessEntry
{
    public function __construct(
        public string $component,
        public ReadinessStatus $status,
        public string $message,
    ) {}
}
