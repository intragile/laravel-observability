<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Broadcast;

/**
 * Result of a single broadcast round trip.
 */
final readonly class ProbeOutcome
{
    public function __construct(
        public bool $delivered,
        public ?float $roundTripMs,
        public ?string $error,
    ) {}

    public static function delivered(float $roundTripMs): self
    {
        return new self(true, $roundTripMs, null);
    }

    public static function failed(string $error): self
    {
        return new self(false, null, $error);
    }
}
