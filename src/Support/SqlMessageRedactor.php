<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Support;

/**
 * Strips the interpolated SQL from database exception messages.
 *
 * Laravel's QueryException message embeds the full SQL with every binding
 * already substituted, for example:
 *
 *   SQLSTATE[HY000]: ... (Connection: mysql, SQL: insert into `salaries`
 *   (`employee_id`, `amount`) values (12, 850000))
 *
 * Logged verbatim, such a line carries salaries, addresses, birth dates or
 * password hashes into long-lived stores (log aggregators, error tables)
 * that usually have no retention limit.
 *
 * What is kept: the SQLSTATE code and the driver's own error text, because
 * they explain what went wrong (missing column, lock timeout, unique key
 * violation). The connection name and, on newer Laravel versions, the
 * host/port/database segment are kept as well. Only the SQL tail is removed.
 *
 * Known limitation, by design: a driver message may itself contain a value,
 * e.g. "Duplicate entry 'jane@example.com' for key ...". It is kept, because
 * without it the message loses its diagnostic value and the conflicting value
 * is normally an identifier rather than sensitive data.
 */
final class SqlMessageRedactor
{
    private const REDACTED = '[redacted]';

    /**
     * Matches Laravel's trailing " (Connection: <name>[, <details>], SQL: <query>)".
     * The lazy group captures the optional "Host: …, Port: …, Database: …"
     * segment that QueryException::formatConnectionDetails() inserts; the "s"
     * modifier makes the tail match across multi-line SQL.
     */
    private const PATTERN = '/ \(Connection: ([^,]+)(.*?), SQL: .*/s';

    public static function redact(string $message): string
    {
        if ($message === '') {
            return $message;
        }

        return preg_replace(
            self::PATTERN,
            ' (Connection: $1$2, SQL: '.self::REDACTED.')',
            $message,
        ) ?? $message;
    }
}
