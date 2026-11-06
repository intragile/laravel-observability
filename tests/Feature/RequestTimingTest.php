<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Tests\Feature;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Exceptions;
use IntrAgile\Observability\Checks\RequestTimingCheck;
use IntrAgile\Observability\Http\Middleware\RecordRequestTiming;
use IntrAgile\Observability\Support\SlowestRequestStore;
use IntrAgile\Observability\Tests\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final class RequestTimingTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('cache.stores.secondary', ['driver' => 'array']);
    }

    /**
     * @param  Router  $router
     */
    protected function defineRoutes($router): void
    {
        $router->get('/named', fn (): string => 'ok')
            ->name('timing.named')
            ->middleware(RecordRequestTiming::class);

        $router->get('/unnamed', fn (): string => 'ok')
            ->middleware(RecordRequestTiming::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->store()->forget();
    }

    private function store(?string $name = null): SlowestRequestStore
    {
        $cache = $this->app?->make(CacheFactory::class);
        $this->assertInstanceOf(CacheFactory::class, $cache);

        return new SlowestRequestStore($cache, $name);
    }

    private function statusOf(RequestTimingCheck $check): string
    {
        return (string) $check->run()->status->value;
    }

    public function test_response_has_x_response_time_header(): void
    {
        $response = $this->get('/named')->assertOk();

        $header = $response->headers->get('X-Response-Time');
        $this->assertIsString($header);
        $this->assertMatchesRegularExpression('/^\d+(\.\d+)?ms$/', $header);
    }

    public function test_named_route_records_route_name(): void
    {
        $this->get('/named')->assertOk();

        $this->assertSame('timing.named', $this->store()->get()['route'] ?? null);
    }

    public function test_unnamed_route_records_the_path(): void
    {
        $this->get('/unnamed')->assertOk();

        $this->assertSame('unnamed', $this->store()->get()['route'] ?? null);
    }

    public function test_missing_route_does_not_throw(): void
    {
        $kernel = $this->app?->make(Kernel::class);
        $this->assertInstanceOf(\Illuminate\Foundation\Http\Kernel::class, $kernel);
        $kernel->pushMiddleware(RecordRequestTiming::class);

        $response = $this->get('/does-not-exist')->assertNotFound();

        $this->assertTrue($response->headers->has('X-Response-Time'));
        $this->assertSame('does-not-exist', $this->store()->get()['route'] ?? null);
    }

    public function test_handle_works_without_a_resolved_route(): void
    {
        $middleware = $this->app?->make(RecordRequestTiming::class);
        $this->assertInstanceOf(RecordRequestTiming::class, $middleware);

        $response = $middleware->handle(Request::create('/x'), fn (): Response => new Response('', 404));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('x', $this->store()->get()['route'] ?? null);
    }

    public function test_cache_failure_does_not_break_the_response(): void
    {
        Exceptions::fake();

        $failingCache = new class implements CacheFactory
        {
            public function store($name = null): CacheRepository
            {
                throw new RuntimeException('Cache store unreachable');
            }
        };

        $config = $this->app?->make(ConfigRepository::class);
        $this->assertInstanceOf(ConfigRepository::class, $config);

        $middleware = new RecordRequestTiming($failingCache, $config);

        $response = $middleware->handle(Request::create('/x'), fn (): Response => new Response('ok', 200));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok', $response->getContent());
        $this->assertTrue($response->headers->has('X-Response-Time'));
        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_smaller_measurement_does_not_overwrite_larger(): void
    {
        $store = $this->store();

        $store->record(900, 'a');
        $store->record(100, 'b');

        $slowest = $store->get();
        $this->assertNotNull($slowest);
        $this->assertSame(900.0, $slowest['ms']);
        $this->assertSame('a', $slowest['route']);

        $store->record(1500, 'c');

        $slowest = $store->get();
        $this->assertNotNull($slowest);
        $this->assertSame(1500.0, $slowest['ms']);
        $this->assertSame('c', $slowest['route']);
    }

    public function test_store_uses_the_documented_cache_key(): void
    {
        $this->store()->record(250, 'a');

        $this->assertIsArray(cache()->get('observability:request_timing:slowest'));
    }

    public function test_fast_request_is_ok(): void
    {
        $this->store()->record(300, 'timing.named');

        $this->assertSame('ok', $this->statusOf(new RequestTimingCheck));
        $this->assertNotNull($this->store()->get());
    }

    public function test_slow_request_warns(): void
    {
        $this->store()->record(700, 'timing.named');

        $result = (new RequestTimingCheck)->run();

        $this->assertSame('warning', $result->status->value);
        $this->assertStringContainsString('timing.named', $result->notificationMessage);
    }

    public function test_very_slow_request_fails_and_forgets_measurement(): void
    {
        $this->store()->record(1200, 'timing.named');

        $this->assertSame('failed', $this->statusOf(new RequestTimingCheck));
        $this->assertNull($this->store()->get());
        $this->assertSame('ok', $this->statusOf(new RequestTimingCheck));
    }

    public function test_custom_thresholds_are_applied(): void
    {
        $this->store()->record(700, 'timing.named');

        $check = (new RequestTimingCheck)->warnWhenSlowerThan(800)->failWhenSlowerThan(2000);

        $this->assertSame('ok', $this->statusOf($check));
    }

    public function test_route_threshold_overrides_default(): void
    {
        $check = fn (): RequestTimingCheck => (new RequestTimingCheck)
            ->routeThresholds(['auth.login' => ['warn' => 1500, 'fail' => 3000]]);

        $this->store()->record(1200, 'auth.login');
        $this->assertSame('ok', $this->statusOf($check()));

        $this->store()->record(1600, 'auth.login');
        $this->assertSame('warning', $this->statusOf($check()));
    }

    public function test_no_measurement_is_ok(): void
    {
        $this->assertSame('ok', $this->statusOf(new RequestTimingCheck));
    }

    public function test_custom_store_is_used(): void
    {
        $this->store('secondary')->record(1200, 'timing.named');

        $this->assertSame('ok', $this->statusOf(new RequestTimingCheck));
        $this->assertSame('failed', $this->statusOf((new RequestTimingCheck)->store('secondary')));
    }
}
