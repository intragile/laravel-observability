<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Readiness;

use Closure;
use Illuminate\Contracts\Config\Repository;
use IntrAgile\Observability\Checks\BackupFreshnessCheck;
use IntrAgile\Observability\Checks\BroadcastCheck;
use IntrAgile\Observability\Checks\ContainerMemoryCheck;
use IntrAgile\Observability\Checks\FailedJobsCheck;
use IntrAgile\Observability\Checks\MailRelayCheck;
use IntrAgile\Observability\Checks\RequestTimingCheck;
use IntrAgile\Observability\Checks\SchemaCompleteCheck;
use IntrAgile\Observability\ErrorDigest\ErrorReport;
use IntrAgile\Observability\Logging\LokiJsonFormatter;
use IntrAgile\Observability\Support\ContainerMemory;
use LogicException;
use Spatie\Health\Checks\Check;
use Throwable;

/**
 * Decides which parts of the package can work in this application, from configuration and the
 * installed optional packages alone.
 *
 * The service provider registers exactly the checks returned by checks(), and inspect() explains
 * the same decisions row by row, so the readiness report can never disagree with what is actually
 * wired. Nothing here performs network I/O; the only filesystem reads are the cgroup memory files
 * and an optional Prometheus password file, which keeps checks() cheap enough to run on every boot.
 *
 * Optional packages are detected by class name strings, never by importing their classes, so this
 * class loads and runs when any of them is missing.
 */
final class Inspector
{
    public const REVERB_CLASS = 'Laravel\Reverb\Reverb';

    public const WEBSOCKET_CLIENT_CLASS = 'WebSocket\Client';

    public const BACKUP_EVENT_CLASS = 'Spatie\Backup\Events\BackupWasSuccessful';

    public const PROMETHEUS_CLASS = 'Spatie\Prometheus\Prometheus';

    public const DOCTOR_CLASS = 'Laravel\Doctor\Doctor';

    /**
     * Config key under observability.health.checks => component name used in the report.
     */
    private const CHECKS = [
        'schema_complete' => 'SchemaCompleteCheck',
        'failed_jobs' => 'FailedJobsCheck',
        'mail_relay' => 'MailRelayCheck',
        'request_timing' => 'RequestTimingCheck',
        'backup_freshness' => 'BackupFreshnessCheck',
        'broadcast' => 'BroadcastCheck',
        'container_memory' => 'ContainerMemoryCheck',
    ];

    /** @var Closure(string): bool */
    private readonly Closure $classExists;

    /** @var Closure(): bool */
    private readonly Closure $tableExists;

    /**
     * @param  (Closure(string): bool)|null  $classExists  detects optional packages; defaults to class_exists()
     * @param  (Closure(): bool)|null  $tableExists  whether the error report table exists; may throw when the
     *                                               database is unreachable
     */
    public function __construct(
        private readonly Repository $config,
        ?Closure $classExists = null,
        ?Closure $tableExists = null,
    ) {
        $this->classExists = $classExists ?? static fn (string $class): bool => class_exists($class);
        $this->tableExists = $tableExists ?? static function (): bool {
            $model = new ErrorReport;

            return $model->getConnection()->getSchemaBuilder()->hasTable($model->getTable());
        };
    }

    public function prometheusInstalled(): bool
    {
        return ($this->classExists)(self::PROMETHEUS_CLASS);
    }

    public function backupInstalled(): bool
    {
        return ($this->classExists)(self::BACKUP_EVENT_CLASS);
    }

    /**
     * The health check instances that can run here, configured from observability.health.checks.
     *
     * @return list<Check>
     */
    public function checks(): array
    {
        $checks = [];

        foreach (array_keys(self::CHECKS) as $key) {
            if ($this->checkSkipReason($key) === null) {
                $checks[] = $this->buildCheck($key);
            }
        }

        return $checks;
    }

    /**
     * @return list<ReadinessEntry>
     */
    public function inspect(): array
    {
        $registers = $this->bool('observability.health.register');

        $entries = [new ReadinessEntry(
            'Health registration',
            $registers ? ReadinessStatus::Ok : ReadinessStatus::Skipped,
            $registers
                ? 'Enabled checks are registered with spatie/laravel-health automatically.'
                : 'observability.health.register is false; register the checks yourself.',
        )];

        foreach (self::CHECKS as $key => $component) {
            $entries[] = $this->checkEntry($key, $component);
        }

        $entries[] = $this->prometheusEntry();
        $entries[] = $this->errorDigestEntry();
        $entries[] = $this->loggingEntry();
        $entries[] = $this->doctorEntry();
        $entries[] = $this->rootGuardEntry();

        return $entries;
    }

    private function checkEntry(string $key, string $component): ReadinessEntry
    {
        $skipReason = $this->checkSkipReason($key);

        if ($skipReason !== null) {
            return new ReadinessEntry($component, ReadinessStatus::Skipped, $skipReason);
        }

        if ($key === 'backup_freshness') {
            $store = $this->heartbeatStore();

            if ($this->string("cache.stores.{$store}.driver") === 'array') {
                return new ReadinessEntry($component, ReadinessStatus::Warning, "The heartbeat cache store [{$store}] uses the array driver, "
                    .'so every heartbeat is lost when the process ends and the check can never pass. Set '
                    .'observability.health.checks.backup_freshness.store to a persistent store.');
            }

            return new ReadinessEntry($component, ReadinessStatus::Ok, "Heartbeats are kept in the [{$store}] cache store.");
        }

        return new ReadinessEntry($component, ReadinessStatus::Ok, 'Enabled.');
    }

    /**
     * Why a check cannot run here, or null when it can.
     */
    private function checkSkipReason(string $key): ?string
    {
        if (! $this->bool("observability.health.checks.{$key}.enabled")) {
            return "Disabled by observability.health.checks.{$key}.enabled.";
        }

        return match ($key) {
            'backup_freshness' => $this->backupInstalled()
                ? null
                : 'spatie/laravel-backup is not installed, so no backup heartbeat is ever recorded.',
            'broadcast' => $this->broadcastSkipReason(),
            'container_memory' => (new ContainerMemory($this->containerRoot()))->limitBytes() === null
                ? 'No cgroup memory limit found at '.$this->containerRoot().'; not running in a memory-limited container.'
                : null,
            default => null,
        };
    }

    private function broadcastSkipReason(): ?string
    {
        $missing = [];

        if (! ($this->classExists)(self::REVERB_CLASS)) {
            $missing[] = 'laravel/reverb';
        }

        if (! ($this->classExists)(self::WEBSOCKET_CLIENT_CLASS)) {
            $missing[] = 'phrity/websocket';
        }

        if ($missing === []) {
            return null;
        }

        return 'Requires laravel/reverb and phrity/websocket; missing: '.implode(', ', $missing).'.';
    }

    private function buildCheck(string $key): Check
    {
        $prefix = "observability.health.checks.{$key}.";

        return match ($key) {
            'schema_complete' => SchemaCompleteCheck::new()
                ->listed($this->int($prefix.'listed', 5))
                ->paths($this->array($prefix.'paths')),
            'failed_jobs' => FailedJobsCheck::new()
                ->windowHours($this->int($prefix.'window_hours', 24))
                ->listed($this->int($prefix.'listed', 5)),
            'mail_relay' => MailRelayCheck::new()
                ->environments($this->strings($prefix.'environments'))
                ->catcherHosts($this->strings($prefix.'catcher_hosts'))
                ->catcherPorts($this->ports($prefix.'catcher_ports'))
                ->holdConfigKey($this->nullableString($prefix.'hold_config_key')),
            'request_timing' => RequestTimingCheck::new()
                ->warnWhenSlowerThan($this->int($prefix.'warn_ms', 500))
                ->failWhenSlowerThan($this->int($prefix.'fail_ms', 1000))
                ->routeThresholds($this->routeThresholds($prefix.'routes'))
                ->store($this->nullableString($prefix.'store')),
            'backup_freshness' => BackupFreshnessCheck::new()
                ->warnAfterHours($this->int($prefix.'warn_hours', 26))
                ->failAfterHours($this->int($prefix.'fail_hours', 48)),
            // Reads its settings from config when it runs, so runtime config changes still apply.
            'broadcast' => BroadcastCheck::new(),
            'container_memory' => ContainerMemoryCheck::new()
                ->root($this->containerRoot())
                ->warnWhenUsedPercentAbove($this->float($prefix.'warn_percent', 90.0))
                ->failWhenUsedPercentAbove($this->float($prefix.'fail_percent', 95.0)),
            default => throw new LogicException("Unknown check [{$key}]."),
        };
    }

    private function prometheusEntry(): ReadinessEntry
    {
        if (! $this->prometheusInstalled()) {
            return new ReadinessEntry('Prometheus', ReadinessStatus::Skipped, 'spatie/laravel-prometheus is not installed; no metrics endpoint is exposed.');
        }

        if (! $this->bool('observability.prometheus.basic_auth.enabled')) {
            return new ReadinessEntry('Prometheus', ReadinessStatus::Ok, 'Basic auth is disabled; the endpoint is protected only by prometheus.middleware.');
        }

        if ($this->prometheusPassword() === '') {
            return new ReadinessEntry('Prometheus', ReadinessStatus::Warning, 'Basic auth is enabled but no password is configured, so every scrape will be rejected. '
                .'Set PROMETHEUS_PASSWORD or PROMETHEUS_PASSWORD_FILE.');
        }

        return new ReadinessEntry('Prometheus', ReadinessStatus::Ok, 'Basic auth is enabled with a password.');
    }

    private function errorDigestEntry(): ReadinessEntry
    {
        if (! $this->bool('observability.error_digest.enabled')) {
            return new ReadinessEntry('Error digest', ReadinessStatus::Skipped, 'Disabled by observability.error_digest.enabled.');
        }

        $problems = [];

        if ($this->nullableString('observability.error_digest.webhook_url') === null) {
            $problems[] = 'no Slack webhook is configured (OBSERVABILITY_SLACK_WEBHOOK), so reports pile up unsent';
        }

        $table = (new ErrorReport)->getTable();

        try {
            if (! ($this->tableExists)()) {
                $problems[] = "the {$table} table does not exist; publish and run the migration";
            }
        } catch (Throwable $exception) {
            $problems[] = "the {$table} table could not be checked (".$exception::class.')';
        }

        if ($problems !== []) {
            return new ReadinessEntry('Error digest', ReadinessStatus::Warning, ucfirst(implode('; ', $problems)).'.');
        }

        return new ReadinessEntry('Error digest', ReadinessStatus::Ok, 'Enabled, with a webhook and the report table in place.');
    }

    private function loggingEntry(): ReadinessEntry
    {
        $channels = [];

        foreach ($this->array('logging.channels') as $name => $channel) {
            if (is_array($channel) && ($channel['formatter'] ?? null) === LokiJsonFormatter::class) {
                $channels[] = (string) $name;
            }
        }

        if ($channels === []) {
            return new ReadinessEntry('Structured logging', ReadinessStatus::Warning, 'No logging channel uses '.LokiJsonFormatter::class
                .' as its formatter, so logs are not emitted as Loki-ready JSON.');
        }

        return new ReadinessEntry('Structured logging', ReadinessStatus::Ok, 'Loki JSON formatter used by: '.implode(', ', $channels).'.');
    }

    private function doctorEntry(): ReadinessEntry
    {
        if (! ($this->classExists)(self::DOCTOR_CLASS)) {
            return new ReadinessEntry('Doctor integration', ReadinessStatus::Skipped, 'laravel/doctor is not installed; the probes are only available through observability:doctor.');
        }

        return new ReadinessEntry('Doctor integration', ReadinessStatus::Ok, 'laravel/doctor is installed.');
    }

    private function rootGuardEntry(): ReadinessEntry
    {
        if (! $this->bool('observability.root_guard.enabled')) {
            return new ReadinessEntry('Root guard', ReadinessStatus::Skipped, 'Disabled by observability.root_guard.enabled.');
        }

        $environments = $this->strings('observability.root_guard.environments');

        return new ReadinessEntry('Root guard', ReadinessStatus::Ok, $environments === []
            ? 'Enabled, but no environment is listed.'
            : 'Artisan refuses to run as root in: '.implode(', ', $environments).'.');
    }

    /**
     * Mirrors the middleware's resolution: the configured password, else the trimmed password file.
     */
    private function prometheusPassword(): string
    {
        $password = $this->nullableString('observability.prometheus.basic_auth.password');

        if ($password !== null) {
            return $password;
        }

        $file = $this->nullableString('observability.prometheus.basic_auth.password_file');

        if ($file === null || ! is_file($file) || ! is_readable($file)) {
            return '';
        }

        $contents = @file_get_contents($file);

        return $contents === false ? '' : trim($contents);
    }

    private function heartbeatStore(): string
    {
        return $this->nullableString('observability.health.checks.backup_freshness.store')
            ?? $this->nullableString('cache.default')
            ?? 'file';
    }

    private function containerRoot(): string
    {
        return $this->nullableString('observability.health.checks.container_memory.root') ?? '/sys/fs/cgroup';
    }

    /**
     * @return array<string, array{warn: int, fail: int}>
     */
    private function routeThresholds(string $key): array
    {
        $thresholds = [];

        foreach ($this->array($key) as $route => $limits) {
            if (is_array($limits) && is_numeric($limits['warn'] ?? null) && is_numeric($limits['fail'] ?? null)) {
                $thresholds[(string) $route] = ['warn' => (int) $limits['warn'], 'fail' => (int) $limits['fail']];
            }
        }

        return $thresholds;
    }

    private function bool(string $key): bool
    {
        return filter_var($this->config->get($key), FILTER_VALIDATE_BOOLEAN);
    }

    private function int(string $key, int $default): int
    {
        $value = $this->config->get($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    private function float(string $key, float $default): float
    {
        $value = $this->config->get($key);

        return is_numeric($value) ? (float) $value : $default;
    }

    private function string(string $key): string
    {
        $value = $this->config->get($key);

        return is_string($value) ? $value : '';
    }

    private function nullableString(string $key): ?string
    {
        $value = $this->config->get($key);

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function array(string $key): array
    {
        $value = $this->config->get($key);

        return is_array($value) ? $value : [];
    }

    /**
     * @return list<int>
     */
    private function ports(string $key): array
    {
        return array_values(array_map(intval(...), array_filter($this->array($key), is_numeric(...))));
    }

    /**
     * @return list<string>
     */
    private function strings(string $key): array
    {
        return array_values(array_filter($this->array($key), is_string(...)));
    }
}
