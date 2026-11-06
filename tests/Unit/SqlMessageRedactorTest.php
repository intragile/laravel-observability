<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Tests\Unit;

use Illuminate\Database\QueryException;
use IntrAgile\Observability\Support\SqlMessageRedactor;
use IntrAgile\Observability\Tests\TestCase;
use PDOException;

final class SqlMessageRedactorTest extends TestCase
{
    public function test_keeps_sqlstate_and_driver_message(): void
    {
        $redacted = SqlMessageRedactor::redact(
            'SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded (Connection: mysql, SQL: update `users` set `salary` = 850000 where `id` = 12)'
        );

        $this->assertStringContainsString('SQLSTATE[HY000]', $redacted);
        $this->assertStringContainsString('Lock wait timeout exceeded', $redacted);
        $this->assertStringNotContainsString('850000', $redacted);
    }

    public function test_removes_every_literal_from_the_sql_tail(): void
    {
        $redacted = SqlMessageRedactor::redact(
            "SQLSTATE[23000]: Integrity constraint violation (Connection: mysql, SQL: select * from users where email = 'a@b.c')"
        );

        $this->assertSame(
            'SQLSTATE[23000]: Integrity constraint violation (Connection: mysql, SQL: [redacted])',
            $redacted,
        );
        $this->assertStringNotContainsString('a@b.c', $redacted);
        $this->assertStringNotContainsString('email', $redacted);
        $this->assertStringNotContainsString('select', $redacted);
    }

    public function test_redacts_multi_line_sql(): void
    {
        $redacted = SqlMessageRedactor::redact(
            "SQLSTATE[42S22]: Column not found (Connection: pgsql, SQL: select *\nfrom accounts\nwhere iban = 'HU42117730161111101800000000')"
        );

        $this->assertSame('SQLSTATE[42S22]: Column not found (Connection: pgsql, SQL: [redacted])', $redacted);
        $this->assertStringNotContainsString('HU42', $redacted);
    }

    public function test_leaves_non_sql_message_unchanged(): void
    {
        $message = 'Attempt to read property "name" on null (in Listener.php, line 12)';

        $this->assertSame($message, SqlMessageRedactor::redact($message));
    }

    public function test_leaves_empty_string_unchanged(): void
    {
        $this->assertSame('', SqlMessageRedactor::redact(''));
    }

    public function test_redacts_real_query_exception_message_with_connection_details(): void
    {
        $exception = new QueryException(
            'mysql',
            'select * from users where email = ?',
            ['a@b.c'],
            new PDOException('SQLSTATE[HY000]: boom'),
            ['driver' => 'mysql', 'host' => 'db', 'port' => 3306, 'database' => 'app'],
        );

        $redacted = SqlMessageRedactor::redact($exception->getMessage());

        $this->assertSame(
            'SQLSTATE[HY000]: boom (Connection: mysql, Host: db, Port: 3306, Database: app, SQL: [redacted])',
            $redacted,
        );
        $this->assertStringNotContainsString('a@b.c', $redacted);
    }

    public function test_redacts_real_query_exception_message_without_connection_details(): void
    {
        $exception = new QueryException(
            'sqlite',
            'insert into salaries (amount) values (?)',
            [850000],
            new PDOException('SQLSTATE[23000]: constraint failed'),
        );

        $this->assertSame(
            'SQLSTATE[23000]: constraint failed (Connection: sqlite, SQL: [redacted])',
            SqlMessageRedactor::redact($exception->getMessage()),
        );
    }

    public function test_keeps_values_inside_the_driver_message_by_design(): void
    {
        $redacted = SqlMessageRedactor::redact(
            "SQLSTATE[23000]: Duplicate entry 'jane@example.com' for key 'users_email_unique' (Connection: mysql, SQL: insert into users (email) values ('jane@example.com'))"
        );

        $this->assertSame(
            "SQLSTATE[23000]: Duplicate entry 'jane@example.com' for key 'users_email_unique' (Connection: mysql, SQL: [redacted])",
            $redacted,
        );
    }
}
