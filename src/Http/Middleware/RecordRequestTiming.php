<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Http\Middleware;

use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use IntrAgile\Observability\Support\SlowestRequestStore;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Measures how long the application took to answer a request.
 *
 * The duration is exposed in the X-Response-Time header and the slowest request is kept in
 * the configured cache store for RequestTimingCheck. Requests rejected by middleware that runs
 * before this one (for example authentication) are not measured. Unmatched routes (404) are
 * only measured when the middleware is registered globally rather than in a route group.
 * A failing cache store never affects the response: the error is reported and the
 * measurement is skipped.
 */
final class RecordRequestTiming
{
    private readonly SlowestRequestStore $store;

    public function __construct(CacheFactory $cache, ConfigRepository $config)
    {
        $store = $config->get('observability.health.checks.request_timing.store');

        $this->store = new SlowestRequestStore($cache, is_string($store) && $store !== '' ? $store : null);
    }

    public function handle(Request $request, Closure $next): Response
    {
        $fallbackStart = microtime(true);

        $response = $next($request);
        assert($response instanceof Response);

        $ms = max(0.0, (microtime(true) - $this->startTime($request, $fallbackStart)) * 1000);

        $response->headers->set('X-Response-Time', number_format($ms, 2, '.', '').'ms');

        try {
            $this->store->record($ms, $this->routeName($request));
        } catch (Throwable $e) {
            report($e);
        }

        return $response;
    }

    private function startTime(Request $request, float $fallback): float
    {
        if (defined('LARAVEL_START')) {
            return (float) constant('LARAVEL_START');
        }

        $start = $request->server('REQUEST_TIME_FLOAT');

        return is_numeric($start) ? (float) $start : $fallback;
    }

    private function routeName(Request $request): string
    {
        $route = $request->route();
        $name = $route instanceof Route ? $route->getName() : null;

        return is_string($name) && $name !== '' ? $name : $request->path();
    }
}
