# Laravel Observability

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)

[![Tests](https://github.com/intragile/laravel-observability/actions/workflows/tests.yml/badge.svg)](https://github.com/intragile/laravel-observability/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/intragile/laravel-observability.svg)](https://packagist.org/packages/intragile/laravel-observability)
[![PHP](https://img.shields.io/packagist/php-v/intragile/laravel-observability.svg)](https://packagist.org/packages/intragile/laravel-observability)
[![License](https://img.shields.io/packagist/l/intragile/laravel-observability.svg)](LICENSE)

**An observability kit for Laravel on top of spatie/laravel-health and spatie/laravel-prometheus:**
Loki-ready JSON logging with SQL redaction, the health checks Spatie does not ship, fail-closed
basic auth for the metrics endpoint, a grouped Slack error digest, and a doctor command that
probes the whole chain live.

[Magyar nyelvű leírás → README.hu.md](README.hu.md)

The package builds on Spatie instead of replacing it. The check framework, the result store,
notifications, the `/prometheus` endpoint and its IP allowlist all stay Spatie's; this package adds
what is missing around them, wires it up from one config file, and tells you what it could not wire.

```bash
composer require intragile/laravel-observability
php artisan observability:install
php artisan observability:doctor --live
```

## What it adds on top of Spatie

| Area | What Spatie already does | What this package adds | What it deliberately does not rewrite |
|---|---|---|---|
| JSON logging | – | `LokiJsonFormatter`: one JSON object per line, `level` as a word, `service`, `env` and static fields on every line | the Monolog handler; HTTP push to Loki is left to a shipper (Promtail, Alloy) or a dedicated package |
| SQL redaction | – | bound values and SQL tails are removed from query exception messages, in the log line and in the error digest table | your exception handler (a hook is provided) |
| Prometheus auth | IP allowlist only; an empty list lets everyone in | basic auth that fails closed, password from env or a mounted secret file, constant-time comparison | the endpoint, rendering and the IP allowlist |
| Prometheus gauges | gauge and collector registration | container memory gauges; Spatie's queue collectors behind one switch | application-specific gauges |
| Migrations applied | – | `SchemaCompleteCheck` | – |
| Real mail relay in production | – | `MailRelayCheck`: fails when production mail goes to Mailpit, Mailtrap's sandbox, `log` and the like | – |
| Broadcasting | – | `BroadcastCheck`: a real WebSocket round trip through Reverb | – |
| Request latency | – | `RecordRequestTiming` middleware + `RequestTimingCheck` | – |
| Failed queue jobs | `QueueCheck` only checks that a worker is alive | `FailedJobsCheck` on the `failed_jobs` table | the queue heartbeat (`QueueCheck`) and `ScheduleCheck` |
| Backup freshness | `BackupsCheck` lists the backup disk on every run, without a warning level | `BackupFreshnessCheck`: one cache read, warning and failure thresholds | the backups themselves and laravel-backup's own monitor |
| Container memory | – | cgroup v1/v2 reading as a check and as gauges; silently skipped outside a container | – |
| Artisan as root | – | refuses to run Artisan as root in production | – |
| Error notifications | – | a grouped, batched Slack digest that marks errors as sent only after delivery | Laravel's own Slack notification channel |
| Setup and diagnostics | – | `observability:install` and `observability:doctor`; diagnostics for `laravel/doctor` | the `laravel/doctor` framework itself |

## Requirements

- PHP 8.3+, Laravel 12 or 13.
- [spatie/laravel-health](https://github.com/spatie/laravel-health) 1.37+ (installed as a dependency).

### Optional packages

Everything that depends on an optional package is switched on when the package is installed and
skipped, without errors, when it is not. `observability:install` and `observability:doctor` show
each skipped part and the reason.

| Package | Turns on | Without it |
|---|---|---|
| `spatie/laravel-prometheus` ^1.3 | basic auth on `/prometheus`, container memory gauges, queue collectors | no metrics endpoint; the Prometheus rows are `skipped` |
| `spatie/laravel-backup` ^9.4 \| ^10.0 | backup heartbeat listener and `BackupFreshnessCheck` | the check is not registered |
| `laravel/reverb` ^1.4 **and** `phrity/websocket` ^4.0 | `BroadcastCheck` and the live broadcast probe | the check is not registered |
| `laravel/doctor` ^0.1 | the observability diagnostics inside `php artisan doctor` | the same probes are available through `observability:doctor` |

```bash
composer require spatie/laravel-prometheus spatie/laravel-backup laravel/reverb phrity/websocket laravel/doctor
```

No `spatie/laravel-prometheus` release supports PHP 8.3 together with Laravel 13 (its Laravel 13
releases need PHP 8.4). On that combination the package runs without it and the metrics rows are
`skipped`; everything else works.

## Installation

```bash
composer require intragile/laravel-observability
php artisan observability:install
```

The service provider is registered automatically. `observability:install`:

1. publishes `config/observability.php` (tag `observability-config`);
2. publishes the error report migration (tag `observability-migrations`) when the error digest is
   enabled, unless a copy of it already exists under any timestamp;
3. prints a readiness table: every component with `ok`, `warning` or `skipped` and the reason;
4. prints the two snippets you add by hand: the logging channel and the exception hook.

| Option | Effect |
|---|---|
| `--force` | overwrite files that were published before |
| `--strict` | exit with status 1 when the readiness table contains a warning |

The readiness table only reflects configuration and installed packages. To prove that the chain
actually works, run `php artisan observability:doctor --live` (see [Doctor](#doctor)).

## Structured logging for Loki

Add a channel to `config/logging.php` and include it in your stack:

```php
'json' => [
    'driver' => 'monolog',
    'handler' => Monolog\Handler\StreamHandler::class,
    'with' => ['stream' => 'php://stderr'],
    'formatter' => \IntrAgile\Observability\Logging\LokiJsonFormatter::class,
],
```

Every record becomes one line of JSON. On top of Monolog's `JsonFormatter` output:

- `level` holds the lowercase level word (`error`), because Loki's level detection reads `level`
  and expects a word; the numeric level moves to `level_value`, and `level_name` (`ERROR`) is kept;
- `service` and `env` are written to every line, followed by the static fields in `logging.extra`
  (which can never overwrite the core fields);
- stack traces are included by default;
- interpolated SQL is redacted from the message, from `context.exception` and from every exception
  in its `previous` chain.

| Key (`observability.logging.*`) | Env | Default |
|---|---|---|
| `service` | `LOG_SERVICE` | `APP_NAME`, else `laravel` |
| `include_stacktraces` | `OBSERVABILITY_LOG_STACKTRACES` | `true` |
| `redact_sql` | – | `true` |
| `extra` | – | `[]`, e.g. `['alias' => env('APP_ALIAS')]` |

The constructor arguments `includeStacktraces`, `redactSql`, `service` and `extra` override the
config for a single channel through Laravel's `formatter_with` key.

Take Loki labels from container labels rather than from the JSON: process output that does not go
through Monolog (php-fpm, supervisord) never reaches this formatter.

### SQL redaction

Laravel's `QueryException` message contains the full SQL with every binding substituted. Logged as
it is, salaries, addresses or password hashes end up in log storage that usually has no retention
limit. `IntrAgile\Observability\Support\SqlMessageRedactor` keeps the SQLSTATE code, the driver's
error text and the connection details, and replaces the SQL tail:

```text
SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'x' for key 'users_email_unique' (Connection: mysql, SQL: [redacted])
```

The driver's own message is kept on purpose, because without it the error loses its meaning. It may
contain a value, as in the `Duplicate entry` example above.

## Health checks

With `observability.health.register` set to `true` (the default) the enabled checks are registered
with spatie/laravel-health automatically. A check whose name is already registered is left alone,
so registering one of them yourself does not break boot. Set `OBSERVABILITY_HEALTH_REGISTER=false`
to register them yourself; each check also works with its fluent setters, for example
`FailedJobsCheck::new()->windowHours(12)`.

Schedule Spatie's runner as usual, or the results go stale (the doctor warns about it):

```php
// routes/console.php
use Spatie\Health\Commands\RunHealthChecksCommand;

Schedule::command(RunHealthChecksCommand::class)->everyMinute();
```

All keys below live under `observability.health.checks`, and each check has an `enabled` key
(default `true`).

| Check | Fails or warns when | Settings and defaults |
|---|---|---|
| `SchemaCompleteCheck` | a migration file has not run, the `migrations` table is missing, or the state cannot be read | `schema_complete.listed` 5 (pending names listed), `paths` `[]` (extra migration directories) |
| `FailedJobsCheck` | a queued job failed within the window; older failures are counted, not judged | `failed_jobs.window_hours` 24, `listed` 5. Reads `queue.failed.table` on `queue.failed.database` |
| `MailRelayCheck` | in a monitored environment the mailer is `log`/`array`/`null`, the SMTP host is empty, a catcher, or this machine, or the port is a catcher port | `mail_relay.environments` `['production']`, `catcher_hosts` (mailhog, mailpit, sandbox.smtp.mailtrap.io, mailcatcher, maildev, smtp4dev), `catcher_ports` `[1025, 1080, 8025]`, `hold_config_key` `null` |
| `RequestTimingCheck` | the slowest request since the last alert reached the threshold (in ms) | `request_timing.warn_ms` 500, `fail_ms` 1000, `routes` `[]`, `store` `null`, `middleware_groups` `['web', 'api']` |
| `BackupFreshnessCheck` | the last successful backup is older than the thresholds, or none was ever recorded | `backup_freshness.warn_hours` 26, `fail_hours` 48, `store` `null` |
| `BroadcastCheck` | a broadcast does not come back over the WebSocket within the timeout, or the round trip is slow | `broadcast.connection` `reverb`, `client_url` `OBSERVABILITY_BROADCAST_URL`, `timeout_seconds` 5.0, `warn_ms` 1000 |
| `ContainerMemoryCheck` | anonymous memory use is above a percentage of the cgroup limit | `container_memory.root` `/sys/fs/cgroup`, `warn_percent` 90, `fail_percent` 95 |

Notes on individual checks:

- **MailRelayCheck.** Catcher hosts match whole dot- or hyphen-separated labels, so
  `mail.example.com` is not a catcher. Only the SMTP transport is inspected; other transports
  (SES, Postmark) are assumed to deliver. When the silence is deliberate, point `hold_config_key` at
  a config key: a truthy value turns the failure into `ok`, and a free-text value is shown as the
  reason.
- **RequestTimingCheck.** When the check is enabled, the provider pushes `RecordRequestTiming` into
  the listed middleware groups. The middleware adds an `X-Response-Time` header and keeps the
  slowest request in the cache. After a warning or failure the measurement is cleared, so the next
  run only sees requests served after the alert. Per-route limits are keyed by route name (the path
  for unnamed routes): `'routes' => ['reports.export' => ['warn' => 3000, 'fail' => 10000]]`.
  Requests that never reach a route group (for example 404s) are not measured.
- **BackupFreshnessCheck.** A listener stores a timestamp whenever spatie/laravel-backup fires
  `BackupWasSuccessful`, so a normal run is a single cache read. The thresholds are compared in
  seconds: 26 hours and 1 minute is past a 26-hour limit. If no heartbeat exists yet, the newest
  archive on the backup disks seeds it once. Use a persistent cache store; the readiness table warns
  when the heartbeat store uses the `array` driver.
- **BroadcastCheck.** See [Broadcast round trip](#broadcast-round-trip-reverb).
- **ContainerMemoryCheck.** Reads `memory.max` and `memory.stat` (`anon`) on cgroup v2, or their v1
  equivalents (`rss`). Page cache is excluded. It is not registered when no cgroup memory limit is
  found, for example on a development machine.

## Prometheus

Requires `spatie/laravel-prometheus`. The package appends
`IntrAgile\Observability\Http\Middleware\PrometheusBasicAuth` to `prometheus.middleware`, after
Spatie's `AllowIps`, so both apply.

```dotenv
PROMETHEUS_USERNAME=prometheus
PROMETHEUS_PASSWORD=a-long-random-secret
# or, with a Docker or Kubernetes secret (a trailing newline is ignored):
PROMETHEUS_PASSWORD_FILE=/run/secrets/prometheus_password
```

The middleware fails closed: when neither `PROMETHEUS_PASSWORD` nor a readable, non-empty
`PROMETHEUS_PASSWORD_FILE` yields a password, every request gets 401, including one with an empty
password. Username and password are both compared with `hash_equals`. Configure the same
credentials in the scrape job:

```yaml
scrape_configs:
  - job_name: laravel
    metrics_path: /prometheus
    basic_auth:
      username: prometheus
      password_file: /etc/prometheus/laravel_password
    static_configs:
      - targets: ['app.example.com']
```

| Key (`observability.prometheus.*`) | Env | Default |
|---|---|---|
| `basic_auth.enabled` | – | `true` |
| `basic_auth.username` | `PROMETHEUS_USERNAME` | `prometheus` |
| `basic_auth.password` | `PROMETHEUS_PASSWORD` | – |
| `basic_auth.password_file` | `PROMETHEUS_PASSWORD_FILE` | – |
| `container_memory_gauges` | – | `true` |
| `queue_collectors.enabled` | – | `false` |
| `queue_collectors.connection` | – | `null` (default connection) |
| `queue_collectors.queues` | – | `['default']` |

With `basic_auth.enabled` set to `false` the middleware is not attached, and the endpoint is
protected only by `prometheus.middleware` and `prometheus.allowed_ips`.

**Gauges.** Inside a memory-limited container the package registers
`container_memory_limit_bytes` and `container_memory_used_bytes`. Spatie prefixes the names with
`prometheus.default_namespace`, so they appear as `app_container_memory_limit_bytes` and
`app_container_memory_used_bytes` by default. Without a cgroup limit they are not registered.

**Queue collectors.** `queue_collectors.enabled` registers Spatie's queue size, pending, delayed,
reserved and oldest pending job collectors for the listed queues.

## Error digest

Records every reported exception and posts a grouped summary to a Slack incoming webhook. It is
off by default.

```dotenv
OBSERVABILITY_ERROR_DIGEST=true
OBSERVABILITY_SLACK_WEBHOOK=https://hooks.slack.com/services/...
```

1. Enable it, then publish and run the migration (`observability:install` publishes it when the
   digest is already enabled):

   ```bash
   php artisan vendor:publish --tag=observability-migrations
   php artisan migrate
   ```

2. Hook the recorder into exception reporting. In `bootstrap/app.php`:

   ```php
   use Illuminate\Foundation\Configuration\Exceptions;
   use IntrAgile\Observability\ErrorDigest\ExceptionHook;

   ->withExceptions(function (Exceptions $exceptions) {
       ExceptionHook::register($exceptions);
   })
   ```

   An application that still has a classic `App\Exceptions\Handler` registers it there:

   ```php
   use IntrAgile\Observability\ErrorDigest\ErrorRecorder;
   use Throwable;

   public function register(): void
   {
       $this->reportable(function (Throwable $e) {
           app(ErrorRecorder::class)->record($e);
       });
   }
   ```

   The hook does not stop propagation, so regular logging continues. The recorder never throws:
   when the table is missing or the database is down it logs a warning and returns, and a static
   guard prevents recursion. While the digest is disabled it writes nothing.

3. Scheduling is automatic. While the digest is enabled, the provider schedules
   `observability:error-digest` with the `schedule` cron expression, `withoutOverlapping()` and
   `onOneServer()`. Your scheduler (`schedule:run` or `schedule:work`) has to be running.

Each run claims the IDs of at most `batch_size` unsent rows, groups them by exception type and
redacted message, and posts one message: the `max_groups` largest groups, each with its count in the
batch and in the last 24 hours, plus a "+N further error type(s)" line. Exactly the claimed rows are
marked as sent, **and only after Slack answered with a 2xx status**. When Slack is down, every row
stays unsent and the next run sends it. A cache lock stops two runs from sending the same rows.
Sent rows older than `retention_days` are deleted. Outside production the instance name is prefixed
with the environment, e.g. `[staging] shop`.

| Key (`observability.error_digest.*`) | Env | Default |
|---|---|---|
| `enabled` | `OBSERVABILITY_ERROR_DIGEST` | `false` |
| `webhook_url` | `OBSERVABILITY_SLACK_WEBHOOK` | – |
| `schedule` | – | `*/5 * * * *` |
| `instance` | `APP_ALIAS`, else `APP_NAME` | `laravel` |
| `max_groups` | – | `10` (at most 45) |
| `batch_size` | – | `500` |
| `retention_days` | – | `30` |
| `links` | – | `[]`, e.g. `['Grafana' => 'https://grafana.example.com']` (shown as buttons) |

Messages are stored already redacted, so SQL bindings never reach the table. Treat the webhook URL
as a secret: anyone who has it can post to the channel.

## Broadcast round trip (Reverb)

Requires `laravel/reverb` and `phrity/websocket`. Instead of trusting that an event was dispatched,
`BroadcastCheck` connects to Reverb as a client would, subscribes to a one-off public channel
(`observability-probe.<uuid>`), broadcasts an `observability.probe` event carrying a random nonce
through the application's broadcaster, and waits until that nonce arrives. The timeout bounds the
whole exchange, so a down or silent Reverb fails the check instead of stalling the health run.

By default the WebSocket URL is built from `broadcasting.connections.reverb.options` (host, port,
scheme). When clients reach Reverb through a different address, for example behind a reverse proxy
or TLS terminator, set the client URL; only its scheme, host and port are used (`https` becomes
`wss`, `http` becomes `ws`):

```dotenv
OBSERVABILITY_BROADCAST_URL=https://ws.example.com
```

## Root guard

Artisan run as root (for example from a `docker exec` root shell) leaves cache and storage files
owned by root, and the web server user can no longer write them. In the environments listed in
`root_guard.environments` (default `['production']`) Artisan refuses to run as root and explains
how to run the command as the web server user instead.

| Key (`observability.root_guard.*`) | Default |
|---|---|
| `enabled` | `true` |
| `environments` | `['production']` |
| `allow_env` | `ARTISAN_ALLOW_ROOT` |

For a deliberate one-off: `ARTISAN_ALLOW_ROOT=1 php artisan <command>`. In CI pipelines that run as
root, set `APP_ENV` to a non-guarded value before `composer install`, because Composer's
`package:discover` hook is already an Artisan call. The guard never runs under unit tests.

## Doctor

```bash
php artisan observability:doctor
php artisan observability:doctor --live --strict
php artisan observability:doctor --live --json --timeout=10
```

`laravel/doctor` checks configuration. `observability:doctor` pushes something through each part of
the chain and reports what came out. It starts with the readiness table from the installer, then
runs the probes:

| Probe | Runs | What it does |
|---|---|---|
| Logging | always | formats a synthetic record with a query exception through every channel of the default log stack; checks for valid JSON, `level`, `service` and `env`, and that the SQL literal was redacted |
| Scheduling | always | checks that `health:check` and, when the digest is enabled, `observability:error-digest` are scheduled |
| Prometheus | always | sends in-process requests to every `prometheus.urls` path: unauthenticated from an outside address (401 or 403 expected), then with the configured credentials (200 expected), and looks for the container memory gauge |
| Error digest | always | checks the webhook and the report table; with `--send-test` posts a test message to Slack |
| Health checks | `--live` | runs every registered health check once, with timing, so a check that crashes or hangs shows up |
| Broadcast | `--live` | runs the real Reverb round trip |

| Option | Effect |
|---|---|
| `--live` | also run the probes that make network calls: every health check and the broadcast round trip |
| `--send-test` | post a test message to the error digest webhook (works with or without `--live`) |
| `--json` | print the report as JSON: `entries` (component, status, message, duration_ms) and `worst` |
| `--strict` | exit with a non-zero status on warnings as well |
| `--timeout=5` | seconds allowed for each network call; a health check slower than this is reported as a warning |

| Exit code | When |
|---|---|
| `0` | no failure; warnings are allowed unless `--strict` is set |
| `1` | at least one failure, or a warning with `--strict` |
| `2` | `--timeout` is not a positive number |

A probe that throws becomes a failed row and the run continues, so one broken component does not
hide the others. Exception messages in the report are SQL-redacted.

### As a deploy gate

Run it after migrations and before switching traffic:

```bash
php artisan migrate --force
php artisan observability:doctor --live --strict --json > observability-report.json
```

A non-zero exit stops the pipeline. Without `--live` and `--send-test` the command opens no
WebSocket and sends no HTTP request outside the process (the Prometheus probe goes through the HTTP
kernel in-process), so it is safe in any environment. It does query the database to check the
error report table when the digest is enabled.

### laravel/doctor integration

When `laravel/doctor` is installed, four diagnostics appear in its `observability` group:
`LoggingIsStructured`, `PrometheusIsProtected`, `HealthChecksPass` and `ErrorDigestIsDeliverable`.

```bash
php artisan doctor --only=observability
php artisan doctor --only=observability --format=json
```

Only the in-process probes run there; no WebSocket connection is opened and no Slack message is
sent. `HealthChecksPass` reports which checks are registered and scheduled, but does not run them.
For the live probes use `observability:doctor --live`.

## What is skipped when not installed

| Missing | Skipped | Still works |
|---|---|---|
| `spatie/laravel-prometheus` | basic auth middleware, memory gauges, queue collectors, the Prometheus probe | everything else, including `ContainerMemoryCheck` |
| `spatie/laravel-backup` | heartbeat listener, `BackupFreshnessCheck` | everything else |
| `laravel/reverb` or `phrity/websocket` | `BroadcastCheck`, the broadcast probe | everything else |
| `laravel/doctor` | the `doctor --only=observability` diagnostics | `observability:doctor` with the same probes |
| a cgroup memory limit | `ContainerMemoryCheck`, the memory gauges | – |

The application boots unchanged in each case; the readiness table and the doctor report show a
`skipped` row with the reason.

## Configuration reference

```bash
php artisan vendor:publish --tag=observability-config
```

| Section | Covered in |
|---|---|
| `logging` | [Structured logging for Loki](#structured-logging-for-loki) |
| `root_guard` | [Root guard](#root-guard) |
| `health.register`, `health.checks.*` | [Health checks](#health-checks) |
| `prometheus` | [Prometheus](#prometheus) |
| `error_digest` | [Error digest](#error-digest) |

## Testing

```bash
composer test
OBSERVABILITY_REVERB_TEST=1 composer test   # also runs the round trip against a real Reverb server
```

## License

MIT, see [LICENSE](LICENSE).

---

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)
