<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Doctor\Probes;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use IntrAgile\Observability\Doctor\DoctorOptions;
use IntrAgile\Observability\Doctor\Probe;
use IntrAgile\Observability\Readiness\Inspector;
use IntrAgile\Observability\Readiness\ReadinessEntry;
use IntrAgile\Observability\Readiness\ReadinessStatus;
use IntrAgile\Observability\Support\ContainerMemory;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requests the metrics endpoint through the application's HTTP kernel, without any network I/O.
 *
 * Reading the middleware configuration cannot prove the endpoint is protected: a route cached
 * before a config change, a middleware that is not applied, or an exception handler that turns a
 * rejection into a 200 all look fine on paper. Sending real requests through the full middleware
 * stack does prove it. The unauthenticated request comes from a documentation address (TEST-NET-3),
 * so an IP allow list protects it the way it would protect an outside scraper.
 */
final class PrometheusProbe implements Probe
{
    private const OUTSIDE_IP = '203.0.113.10';

    private const MEMORY_GAUGE = 'container_memory_limit_bytes';

    public function __construct(
        private readonly Inspector $inspector,
        private readonly Container $app,
        private readonly Repository $config,
    ) {}

    public function name(): string
    {
        return 'Prometheus probe';
    }

    public function live(): bool
    {
        return false;
    }

    public function run(DoctorOptions $options): array
    {
        if (! $this->inspector->prometheusInstalled()) {
            return [new ReadinessEntry('Prometheus endpoint', ReadinessStatus::Skipped, 'spatie/laravel-prometheus is not installed; there is no metrics endpoint to probe.')];
        }

        if (! filter_var($this->config->get('prometheus.enabled', true), FILTER_VALIDATE_BOOLEAN)) {
            return [new ReadinessEntry('Prometheus endpoint', ReadinessStatus::Skipped, 'prometheus.enabled is false; no metrics endpoint is registered.')];
        }

        $urls = $this->config->get('prometheus.urls');
        $paths = array_values(array_filter(is_array($urls) ? $urls : [], static fn (mixed $url): bool => is_string($url) && $url !== ''));

        if ($paths === []) {
            return [new ReadinessEntry('Prometheus endpoint', ReadinessStatus::Warning, 'prometheus.urls is empty; no metrics endpoint is registered.')];
        }

        $entries = [];

        foreach ($paths as $path) {
            array_push($entries, ...$this->probePath('/'.ltrim($path, '/')));
        }

        return $entries;
    }

    /**
     * @return list<ReadinessEntry>
     */
    private function probePath(string $path): array
    {
        $entries = [$this->unauthenticated($path)];

        $basicAuth = filter_var($this->config->get('observability.prometheus.basic_auth.enabled'), FILTER_VALIDATE_BOOLEAN);
        $server = ['REMOTE_ADDR' => $this->allowedIp()];

        if ($basicAuth) {
            $password = $this->password();

            if ($password === '') {
                $entries[] = new ReadinessEntry("Prometheus {$path} (authenticated)", ReadinessStatus::Warning, 'Basic auth is enabled without a password, so '
                    .'every scrape is rejected. Set PROMETHEUS_PASSWORD or PROMETHEUS_PASSWORD_FILE.');

                return $entries;
            }

            $username = $this->config->get('observability.prometheus.basic_auth.username');
            $server['PHP_AUTH_USER'] = is_string($username) ? $username : '';
            $server['PHP_AUTH_PW'] = $password;
        }

        $response = $this->request($path, $server);
        $status = $response->getStatusCode();
        $who = $basicAuth ? 'An authenticated request' : 'A request from an allowed address';

        if ($status !== Response::HTTP_OK) {
            $entries[] = new ReadinessEntry("Prometheus {$path} (authenticated)", ReadinessStatus::Failed, "{$who} got HTTP {$status}, "
                .'so Prometheus cannot scrape the endpoint with the configured credentials.');

            return $entries;
        }

        $entries[] = new ReadinessEntry("Prometheus {$path} (authenticated)", ReadinessStatus::Ok, "{$who} was answered with HTTP 200.");
        $entries[] = $this->memoryGauge($path, (string) $response->getContent());

        return $entries;
    }

    private function unauthenticated(string $path): ReadinessEntry
    {
        $component = "Prometheus {$path} (unauthenticated)";
        $status = $this->request($path, ['REMOTE_ADDR' => self::OUTSIDE_IP])->getStatusCode();
        $request = 'an unauthenticated request from '.self::OUTSIDE_IP;

        return match (true) {
            in_array($status, [Response::HTTP_UNAUTHORIZED, Response::HTTP_FORBIDDEN], true) => new ReadinessEntry($component, ReadinessStatus::Ok, ucfirst($request)." was rejected with HTTP {$status}."),
            $status >= 200 && $status < 300 => new ReadinessEntry($component, ReadinessStatus::Failed, "The metrics endpoint is open: {$request} got HTTP {$status}. "
                .'Enable observability.prometheus.basic_auth or restrict prometheus.allowed_ips.'),
            $status === Response::HTTP_NOT_FOUND => new ReadinessEntry($component, ReadinessStatus::Failed, "{$path} returned HTTP 404; the metrics route is not registered (stale route cache?)."),
            default => new ReadinessEntry($component, ReadinessStatus::Failed, ucfirst($request)." got HTTP {$status} instead of 401."),
        };
    }

    /**
     * The memory gauges are only registered inside a memory-limited container, so they are only
     * expected there. Spatie prefixes metric names with the default namespace, hence the substring.
     */
    private function memoryGauge(string $path, string $body): ReadinessEntry
    {
        $component = "Prometheus {$path} (container memory gauge)";

        if (! filter_var($this->config->get('observability.prometheus.container_memory_gauges'), FILTER_VALIDATE_BOOLEAN)) {
            return new ReadinessEntry($component, ReadinessStatus::Skipped, 'Disabled by observability.prometheus.container_memory_gauges.');
        }

        $root = $this->config->get('observability.health.checks.container_memory.root');

        if ((new ContainerMemory(is_string($root) && $root !== '' ? $root : '/sys/fs/cgroup'))->limitBytes() === null) {
            return new ReadinessEntry($component, ReadinessStatus::Skipped, 'No cgroup memory limit found; the gauge is only exposed inside a memory-limited container.');
        }

        if (! str_contains($body, self::MEMORY_GAUGE)) {
            return new ReadinessEntry($component, ReadinessStatus::Failed, self::MEMORY_GAUGE.' is missing from the metrics output although a cgroup memory limit exists.');
        }

        return new ReadinessEntry($component, ReadinessStatus::Ok, self::MEMORY_GAUGE.' is exposed.');
    }

    /**
     * Handles the request in-process. The current request binding is restored afterwards, because
     * the kernel replaces it and the rest of the doctor run must not see the synthetic request.
     *
     * The kernel is deliberately not terminated: that would run the application's terminating
     * callbacks in the middle of the command.
     *
     * @param  array<string, string>  $server
     */
    private function request(string $path, array $server): Response
    {
        $previous = $this->app->bound('request') ? $this->app->make('request') : null;

        try {
            return $this->app->make(Kernel::class)->handle(Request::create($path, 'GET', server: $server));
        } finally {
            if ($previous !== null) {
                $this->app->instance('request', $previous);
            }

            Facade::clearResolvedInstance('request');
        }
    }

    /**
     * An address the IP allow list accepts, so the authenticated request tests the credentials
     * rather than the allow list. For a CIDR range its network address is used.
     */
    private function allowedIp(): string
    {
        $allowed = $this->config->get('prometheus.allowed_ips');

        foreach (is_array($allowed) ? $allowed : [] as $entry) {
            $ip = is_string($entry) ? explode('/', $entry, 2)[0] : '';

            if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                return $ip;
            }
        }

        return '127.0.0.1';
    }

    /**
     * Resolved the same way as by the basic auth middleware: the configured password, else the
     * trimmed contents of the password file.
     */
    private function password(): string
    {
        $password = $this->config->get('observability.prometheus.basic_auth.password');

        if (is_string($password) && $password !== '') {
            return $password;
        }

        $file = $this->config->get('observability.prometheus.basic_auth.password_file');

        if (! is_string($file) || $file === '' || ! is_file($file) || ! is_readable($file)) {
            return '';
        }

        $contents = @file_get_contents($file);

        return $contents === false ? '' : trim($contents);
    }
}
