<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Tests\Unit;

use DateTimeImmutable;
use Illuminate\Database\QueryException;
use IntrAgile\Observability\Logging\LokiJsonFormatter;
use IntrAgile\Observability\Tests\TestCase;
use LogicException;
use Monolog\Level;
use Monolog\LogRecord;
use PDOException;
use RuntimeException;
use stdClass;

/**
 * Loki's level detection reads the "level" field and expects a word there,
 * while stock Monolog writes the numeric value. These tests pin the contract
 * Grafana relies on; if they fail, every line shows up as level "unknown".
 */
final class LokiJsonFormatterTest extends TestCase
{
    private const SQL_MESSAGE = "SQLSTATE[23000]: Integrity constraint violation (Connection: mysql, SQL: select * from users where email = 'a@b.c')";

    /**
     * @param  array<mixed>  $context
     */
    private function record(Level $level = Level::Error, string $message = 'something happened', array $context = [], string $channel = 'app'): LogRecord
    {
        return new LogRecord(
            datetime: new DateTimeImmutable('2026-07-14 10:00:00'),
            channel: $channel,
            level: $level,
            message: $message,
            context: $context,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $line): array
    {
        $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param  array<mixed>  $context
     * @return array<string, mixed>
     */
    private function format(Level $level = Level::Error, string $message = 'something happened', array $context = [], ?LokiJsonFormatter $formatter = null, string $channel = 'app'): array
    {
        $formatter ??= new LokiJsonFormatter;

        return $this->decode($formatter->format($this->record($level, $message, $context, $channel)));
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function exceptionOf(array $record): array
    {
        $context = $record['context'] ?? null;
        $this->assertIsArray($context);
        $exception = $context['exception'] ?? null;
        $this->assertIsArray($exception);

        /** @var array<string, mixed> $exception */
        return $exception;
    }

    private function queryException(): QueryException
    {
        return new QueryException(
            'mysql',
            'select * from users where email = ?',
            ['a@b.c'],
            new PDOException('SQLSTATE[HY000]: boom'),
            ['driver' => 'mysql', 'host' => 'db', 'port' => 3306, 'database' => 'app'],
        );
    }

    public function test_level_is_lowercase_word_and_numeric_value_moves_to_level_value(): void
    {
        $record = $this->format(Level::Error);

        $this->assertSame('error', $record['level']);
        $this->assertSame(400, $record['level_value']);
        $this->assertIsInt($record['level_value']);
        $this->assertSame('ERROR', $record['level_name']);
    }

    public function test_every_monolog_level_maps_to_a_lowercase_word(): void
    {
        $expected = [
            Level::Debug->value => 'debug',
            Level::Info->value => 'info',
            Level::Notice->value => 'notice',
            Level::Warning->value => 'warning',
            Level::Error->value => 'error',
            Level::Critical->value => 'critical',
            Level::Alert->value => 'alert',
            Level::Emergency->value => 'emergency',
        ];

        foreach (Level::cases() as $level) {
            $record = $this->format($level);

            $this->assertSame($expected[$level->value], $record['level'], "Level {$level->getName()} mapped to the wrong word.");
            $this->assertSame($level->value, $record['level_value']);
            $this->assertSame($level->getName(), $record['level_name']);
        }
    }

    public function test_service_env_and_extra_fields_are_hoisted_to_top_level(): void
    {
        config()->set('observability.logging.service', 'billing-api');
        config()->set('observability.logging.extra', ['alias' => 'acme', 'region' => 'eu']);
        config()->set('app.env', 'staging');

        $record = $this->format(Level::Info);

        $this->assertSame('billing-api', $record['service']);
        $this->assertSame('staging', $record['env']);
        $this->assertSame('acme', $record['alias']);
        $this->assertSame('eu', $record['region']);
    }

    public function test_constructor_overrides_take_precedence_over_config(): void
    {
        config()->set('observability.logging.service', 'billing-api');
        config()->set('observability.logging.extra', ['alias' => 'acme']);

        $formatter = new LokiJsonFormatter(service: 'worker', extra: ['alias' => 'override', 'zone' => 'b']);

        $record = $this->format(Level::Info, formatter: $formatter);

        $this->assertSame('worker', $record['service']);
        $this->assertSame('override', $record['alias']);
        $this->assertSame('b', $record['zone']);
    }

    public function test_extra_fields_cannot_overwrite_core_fields(): void
    {
        $formatter = new LokiJsonFormatter(extra: [
            'message' => 'forged',
            'level' => 'debug',
            'context' => 'forged',
            'channel' => 'forged',
            'custom' => 'kept',
        ]);

        $record = $this->format(Level::Error, 'real message', ['user_id' => 42], $formatter);

        $this->assertSame('real message', $record['message']);
        $this->assertSame('error', $record['level']);
        $this->assertSame(['user_id' => 42], $record['context']);
        $this->assertSame('app', $record['channel']);
        $this->assertSame('kept', $record['custom']);
    }

    public function test_extra_fields_with_unsupported_values_are_dropped(): void
    {
        config()->set('observability.logging.extra', ['ok' => 'yes', 'object' => new stdClass, 0 => 'numeric-key']);

        $record = $this->format(Level::Info);

        $this->assertSame('yes', $record['ok']);
        $this->assertArrayNotHasKey('object', $record);
        $this->assertArrayNotHasKey('0', $record);
    }

    public function test_channel_and_context_are_preserved(): void
    {
        $this->assertSame('app', $this->format(Level::Info, channel: 'app')['channel']);
        $this->assertSame('slow_queries', $this->format(Level::Warning, channel: 'slow_queries')['channel']);
        $this->assertSame(['user_id' => 42], $this->format(Level::Error, context: ['user_id' => 42])['context']);
    }

    public function test_output_is_a_single_json_line(): void
    {
        $formatter = new LokiJsonFormatter;

        $line = $formatter->format($this->record(Level::Error, "first line\nsecond line"));

        $this->assertStringEndsWith("\n", $line);
        $this->assertSame(1, substr_count($line, "\n"));
        $this->assertSame("first line\nsecond line", $this->decode($line)['message']);
    }

    public function test_sql_tail_is_redacted_from_message(): void
    {
        $record = $this->format(Level::Error, self::SQL_MESSAGE);

        $this->assertSame('SQLSTATE[23000]: Integrity constraint violation (Connection: mysql, SQL: [redacted])', $record['message']);
    }

    public function test_sql_tail_is_redacted_from_exception_message_in_context(): void
    {
        $exception = $this->queryException();

        $record = $this->format(Level::Error, 'query failed', ['exception' => $exception]);
        $normalized = $this->exceptionOf($record);

        $this->assertSame(QueryException::class, $normalized['class']);
        $this->assertSame(
            'SQLSTATE[HY000]: boom (Connection: mysql, Host: db, Port: 3306, Database: app, SQL: [redacted])',
            $normalized['message'],
        );
        $this->assertStringNotContainsString('a@b.c', json_encode($record, JSON_THROW_ON_ERROR));
    }

    public function test_sql_tail_is_redacted_from_wrapped_exception_chain(): void
    {
        $wrapped = new RuntimeException('outer', 0, new LogicException('middle', 0, $this->queryException()));

        $record = $this->format(Level::Error, 'import failed', ['exception' => $wrapped]);
        $outer = $this->exceptionOf($record);

        $this->assertSame('outer', $outer['message']);
        $this->assertIsArray($outer['previous']);
        $this->assertSame('middle', $outer['previous']['message']);
        $this->assertIsArray($outer['previous']['previous']);
        $this->assertSame(QueryException::class, $outer['previous']['previous']['class']);
        $this->assertSame(
            'SQLSTATE[HY000]: boom (Connection: mysql, Host: db, Port: 3306, Database: app, SQL: [redacted])',
            $outer['previous']['previous']['message'],
        );
        $this->assertStringNotContainsString('a@b.c', json_encode($record, JSON_THROW_ON_ERROR));
    }

    public function test_sql_tail_is_redacted_from_std_class_previous_chain_without_mutation(): void
    {
        $previous = new stdClass;
        $previous->message = self::SQL_MESSAGE;
        $exception = new stdClass;
        $exception->message = 'outer';
        $exception->previous = $previous;

        $record = $this->format(Level::Error, 'x', ['exception' => $exception]);
        $outer = $this->exceptionOf($record);

        $this->assertIsArray($outer['previous']);
        $this->assertSame(
            'SQLSTATE[23000]: Integrity constraint violation (Connection: mysql, SQL: [redacted])',
            $outer['previous']['message'],
        );
        $this->assertSame(self::SQL_MESSAGE, $previous->message, 'The caller\'s object must not be mutated.');
    }

    public function test_sql_tail_is_redacted_when_exception_context_is_std_class(): void
    {
        $exception = new stdClass;
        $exception->message = self::SQL_MESSAGE;

        $record = $this->format(Level::Error, 'query failed', ['exception' => $exception]);

        $this->assertSame(
            'SQLSTATE[23000]: Integrity constraint violation (Connection: mysql, SQL: [redacted])',
            $this->exceptionOf($record)['message'],
        );
        $this->assertSame(self::SQL_MESSAGE, $exception->message, 'The caller\'s object must not be mutated.');
    }

    public function test_nested_std_class_context_does_not_break_the_formatter(): void
    {
        $payload = new stdClass;
        $payload->exception = new stdClass;
        $payload->exception->message = self::SQL_MESSAGE;

        $record = $this->format(Level::Error, 'x', ['payload' => $payload]);

        $this->assertSame('error', $record['level']);
    }

    public function test_empty_context_does_not_break_the_formatter(): void
    {
        $formatter = new LokiJsonFormatter;

        $line = $formatter->format($this->record(Level::Error, self::SQL_MESSAGE, []));
        $record = $this->decode($line);

        $this->assertStringContainsString('"context":{}', $line);
        $this->assertSame('error', $record['level']);
        $this->assertStringContainsString('[redacted]', (string) json_encode($record['message']));
    }

    public function test_sql_is_left_intact_when_redaction_is_disabled(): void
    {
        $formatter = new LokiJsonFormatter(redactSql: false);

        $record = $this->format(Level::Error, self::SQL_MESSAGE, ['exception' => $this->queryException()], $formatter);

        $this->assertSame(self::SQL_MESSAGE, $record['message']);
        $exceptionMessage = $this->exceptionOf($record)['message'];
        $this->assertIsString($exceptionMessage);
        $this->assertStringContainsString('SQL: select * from users where email = a@b.c', $exceptionMessage);
    }

    public function test_sql_redaction_can_be_disabled_via_config(): void
    {
        config()->set('observability.logging.redact_sql', false);

        $this->assertSame(self::SQL_MESSAGE, $this->format(Level::Error, self::SQL_MESSAGE)['message']);
    }

    public function test_exception_carries_stack_trace_by_default(): void
    {
        $exception = new RuntimeException('outer', 0, new LogicException('inner'));

        $normalized = $this->exceptionOf($this->format(Level::Error, 'boom', ['exception' => $exception]));

        $this->assertArrayHasKey('trace', $normalized);
        $this->assertIsArray($normalized['trace']);
        $this->assertNotEmpty($normalized['trace']);
        $this->assertIsString($normalized['trace'][0]);
        $this->assertMatchesRegularExpression('/\.php:\d+$/', $normalized['trace'][0]);

        $previous = $normalized['previous'] ?? null;
        $this->assertIsArray($previous);
        $this->assertArrayHasKey('trace', $previous);
        $this->assertNotEmpty($previous['trace']);
    }

    public function test_stack_trace_is_omitted_when_disabled(): void
    {
        $context = ['exception' => new RuntimeException('boom')];

        $viaArgument = $this->exceptionOf($this->format(Level::Error, 'boom', $context, new LokiJsonFormatter(includeStacktraces: false)));
        $this->assertArrayNotHasKey('trace', $viaArgument);

        config()->set('observability.logging.include_stacktraces', false);

        $viaConfig = $this->exceptionOf($this->format(Level::Error, 'boom', $context));
        $this->assertArrayNotHasKey('trace', $viaConfig);
    }

    public function test_is_instantiable_without_arguments(): void
    {
        $formatter = new LokiJsonFormatter;

        $this->assertSame('info', $this->format(Level::Info, formatter: $formatter)['level']);
    }

    public function test_container_resolves_named_formatter_with_arguments(): void
    {
        $formatter = $this->app?->make(LokiJsonFormatter::class, ['includeStacktraces' => false, 'service' => 'from-formatter-with']);

        $this->assertInstanceOf(LokiJsonFormatter::class, $formatter);

        $record = $this->format(Level::Error, 'boom', ['exception' => new RuntimeException('boom')], $formatter);

        $this->assertSame('from-formatter-with', $record['service']);
        $this->assertArrayNotHasKey('trace', $this->exceptionOf($record));
    }
}
