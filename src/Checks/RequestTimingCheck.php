<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Checks;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use IntrAgile\Observability\Support\SlowestRequestStore;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Reports the slowest request recorded by RecordRequestTiming.
 *
 * Once a warning or failure has been reported the measurement is cleared, so the next run
 * reflects only requests served after the alert.
 */
final class RequestTimingCheck extends Check
{
    private int $warnMs = 500;

    private int $failMs = 1000;

    /** @var array<string, array{warn: int, fail: int}> */
    private array $routeThresholds = [];

    private ?string $cacheStore = null;

    public function warnWhenSlowerThan(int $ms): static
    {
        $this->warnMs = $ms;

        return $this;
    }

    public function failWhenSlowerThan(int $ms): static
    {
        $this->failMs = $ms;

        return $this;
    }

    /**
     * @param  array<string, array{warn: int, fail: int}>  $thresholds  keyed by route name or path
     */
    public function routeThresholds(array $thresholds): static
    {
        $this->routeThresholds = $thresholds;

        return $this;
    }

    public function store(?string $store): static
    {
        $this->cacheStore = $store;

        return $this;
    }

    public function run(): Result
    {
        $store = new SlowestRequestStore(app(CacheFactory::class), $this->cacheStore);
        $slowest = $store->get();

        if ($slowest === null) {
            return Result::make()->ok();
        }

        $thresholds = $this->routeThresholds[$slowest['route']] ?? ['warn' => $this->warnMs, 'fail' => $this->failMs];
        $ms = number_format($slowest['ms'], 2, '.', '');

        $result = Result::make()
            ->meta($slowest)
            ->shortSummary($ms.'ms');

        $message = "Slowest request took {$ms}ms on route {$slowest['route']} at {$slowest['at']}.";

        if ($slowest['ms'] >= $thresholds['fail']) {
            $store->forget();

            return $result->failed($message);
        }

        if ($slowest['ms'] >= $thresholds['warn']) {
            $store->forget();

            return $result->warning($message);
        }

        return $result->ok();
    }
}
