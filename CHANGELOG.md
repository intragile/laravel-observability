# Changelog

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- Opt-in scheduling of `health:check` (`health.schedule`), held back for a warmup window after
  each deploy so the first runs do not alert on heartbeats that are not written yet. An optional
  `deploy_id` restarts the window when storage survives the deploy.
- `HealthJsonController` and an opt-in route (`health.json_endpoint`): Spatie's JSON results
  endpoint, except that a `?fresh` run never sends notifications, for deploy gates.
- `CheckFailedNotification`, which sends only from the configured environments (default
  production, overridable with `HEALTH_NOTIFICATIONS_ENABLED`) and labels the message with the
  environment, the instance name and the deploy ID; `NotificationPolicy` holds the rule.
- `HealthChecksPass` (laravel/doctor) now reads the latest stored health run: failed or crashed
  checks fail it, and a missing or stale run (`health.stored_results.stale_minutes`) warns.

### Changed

- The doctor's scheduling probe reports `health:check` as skipped, not missing, during the deploy
  warmup.

## [1.0.0] - 2026-11-06

### Added

- Auto-discovered `ObservabilityServiceProvider` that wires every component from
  `config/observability.php` and skips the parts whose optional package
  (spatie/laravel-prometheus, spatie/laravel-backup, laravel/reverb with phrity/websocket,
  laravel/doctor) is not installed.
- `LokiJsonFormatter`: one-line JSON logs with `level` as a lowercase word, `level_value`,
  `service`, `env` and configurable static fields, stack traces by default and SQL redaction.
- `SqlMessageRedactor`, which removes the interpolated SQL from query exception messages while
  keeping the SQLSTATE code, the driver message and the connection details.
- Health checks for spatie/laravel-health, registered automatically: `SchemaCompleteCheck`,
  `FailedJobsCheck`, `MailRelayCheck`, `RequestTimingCheck` (with the `RecordRequestTiming`
  middleware), `BackupFreshnessCheck` (with a backup heartbeat listener), `BroadcastCheck` and
  `ContainerMemoryCheck`.
- `PrometheusBasicAuth` middleware for the spatie/laravel-prometheus endpoint, with the password
  taken from `PROMETHEUS_PASSWORD` or a secret file (`PROMETHEUS_PASSWORD_FILE`).
- Container memory gauges (cgroup v1 and v2) and an option to register Spatie's queue collectors.
- Error digest: `ErrorRecorder`, `ExceptionHook`, the `observability_error_reports` migration and
  the scheduled `observability:error-digest` command, which posts grouped errors to a Slack
  incoming webhook.
- Root guard that refuses to run Artisan as root in the configured environments, with an
  `ARTISAN_ALLOW_ROOT` override.
- `observability:install`, which publishes the config and the migration and prints a readiness
  report and the remaining manual steps.
- `observability:doctor` with `--live`, `--send-test`, `--json`, `--strict` and `--timeout`, which
  probes logging, scheduling, the Prometheus endpoint, the error digest, every health check and the
  broadcast round trip, and exits with a status usable as a deploy gate.
- laravel/doctor diagnostics in the `observability` group: `LoggingIsStructured`,
  `PrometheusIsProtected`, `HealthChecksPass` and `ErrorDigestIsDeliverable`.

### Changed compared with previous in-house copies

This package replaces earlier copies of the same components that lived inside individual
applications. Behaviour differs from those copies in the following ways:

- **Backup freshness thresholds are exact.** The age of the last backup is compared in seconds
  instead of truncated whole hours, so 26 hours and 1 minute is past a 26-hour limit.
- **Prometheus basic auth fails closed.** Without a configured password every request is rejected,
  including one with an empty password; previously an empty password got through when no password
  was set. Username and password are compared with `hash_equals` instead of `!==`.
- **`FailedJobsCheck` reads Laravel's `failed_jobs` table.** Some previous copies used the name for
  a check of scheduled command status. Only failures within the window decide the result; older
  ones are counted.
- **`BroadcastCheck` performs a real round trip.** It subscribes to Reverb over a WebSocket,
  broadcasts a nonce and waits for it to arrive. The previous check wrote a heartbeat before the
  event was dispatched, so it only proved that the scheduler was running.
- **The error digest marks rows as sent only after successful delivery.** Previously rows could be
  marked before the message was sent, so a Slack outage lost them. Rows are now claimed by ID,
  grouped by type and message, protected by a cache lock against parallel runs, and left unsent
  when Slack does not answer with a 2xx status.
- **SQL redaction is stricter and applied everywhere.** The pattern also covers the connection
  details segment of newer Laravel versions and multi-line SQL. It is applied to the log message,
  the logged exception and every exception in its `previous` chain, and to the stored error digest
  messages; some previous copies redacted only one of these or none.

[Unreleased]: https://github.com/intragile/laravel-observability/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/intragile/laravel-observability/releases/tag/v1.0.0

---

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)
