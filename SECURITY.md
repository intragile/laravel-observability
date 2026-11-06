# Security Policy

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)

## Reporting a vulnerability

Please report security issues privately to **info@intragile.eu** rather than opening a public
issue. Include the affected version and, if possible, steps to reproduce. You will receive a
reply within a few working days.

## Security-relevant behaviour

These parts of the package exist to protect data or endpoints. A way around any of them is a
security issue and should be reported privately:

- **Metrics endpoint authentication.** `PrometheusBasicAuth` fails closed: when no password is
  configured, or the password file is missing, unreadable or empty, every request is rejected.
  Credentials are compared with `hash_equals`. A request that reaches the metrics without the
  configured credentials is a vulnerability.
- **SQL redaction.** `SqlMessageRedactor` removes the interpolated SQL, and with it the bound
  values, from query exception messages before they are logged by `LokiJsonFormatter`, stored by
  the error digest or shown in the doctor report. A message format that still leaks bindings is a
  vulnerability. By design, the driver's own error text is kept, and it may contain a value (for
  example in a duplicate key error).
- **Error digest webhook.** The Slack webhook URL (`OBSERVABILITY_SLACK_WEBHOOK`) is a secret:
  anyone who has it can post to the channel. Keep it out of version control. The package never
  prints it; the doctor reports delivery failures by exception class only.

## Deployment advice

- Set `PROMETHEUS_PASSWORD` or `PROMETHEUS_PASSWORD_FILE` before exposing `/prometheus`, and keep
  `prometheus.allowed_ips` restricted where possible.
- Leave `observability.logging.redact_sql` enabled.
- Run `php artisan observability:doctor` in your deployment pipeline: it fails when the metrics
  endpoint answers an unauthenticated request or a JSON log channel leaks an SQL literal.

---

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)
