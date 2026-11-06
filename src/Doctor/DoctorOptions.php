<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Doctor;

/**
 * How far a doctor run may go.
 */
final readonly class DoctorOptions
{
    /**
     * @param  bool  $live  run the probes that make network calls
     * @param  bool  $sendTest  deliver a real test message where a probe supports it
     * @param  float  $timeout  seconds; bounds every network call and marks slower steps as warnings
     */
    public function __construct(
        public bool $live,
        public bool $sendTest,
        public float $timeout,
    ) {}

    /**
     * The options used where nothing may leave the process, such as inside laravel/doctor.
     */
    public static function staticOnly(float $timeout = 5.0): self
    {
        return new self(live: false, sendTest: false, timeout: $timeout);
    }
}
