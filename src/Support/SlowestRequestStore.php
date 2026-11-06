<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Support;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;

/**
 * Keeps the slowest request seen since the measurement was last cleared.
 *
 * The duration, route and timestamp are stored together under a single cache key so they can
 * never drift apart. Updating is a read-compare-write sequence and is not atomic: under heavy
 * concurrency a new maximum may occasionally be lost. That is an acceptable inaccuracy for a
 * health signal and avoids taking a lock on every request.
 */
final class SlowestRequestStore
{
    public const KEY = 'observability:request_timing:slowest';

    public const TTL_SECONDS = 604800;

    public function __construct(
        private readonly CacheFactory $cache,
        private readonly ?string $store = null,
    ) {}

    /**
     * Stores the measurement only when it is slower than the current maximum.
     */
    public function record(float $ms, string $route): void
    {
        $current = $this->get();

        if ($current !== null && $current['ms'] >= $ms) {
            return;
        }

        $this->repository()->put(self::KEY, [
            'ms' => round($ms, 2),
            'route' => $route,
            'at' => now()->toIso8601String(),
        ], self::TTL_SECONDS);
    }

    /**
     * @return array{ms: float, route: string, at: string}|null
     */
    public function get(): ?array
    {
        $value = $this->repository()->get(self::KEY);

        if (! is_array($value)) {
            return null;
        }

        $ms = $value['ms'] ?? null;
        $route = $value['route'] ?? null;
        $at = $value['at'] ?? null;

        if (! is_int($ms) && ! is_float($ms)) {
            return null;
        }

        if (! is_string($route) || ! is_string($at)) {
            return null;
        }

        return ['ms' => (float) $ms, 'route' => $route, 'at' => $at];
    }

    public function forget(): void
    {
        $this->repository()->forget(self::KEY);
    }

    private function repository(): Repository
    {
        return $this->cache->store($this->store);
    }
}
