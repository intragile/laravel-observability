<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Logging;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use IntrAgile\Observability\Support\SqlMessageRedactor;
use Monolog\Formatter\JsonFormatter;
use Monolog\LogRecord;
use stdClass;

/**
 * One-line JSON log formatter tuned for Grafana Loki.
 *
 * Stock Monolog writes the numeric level (400) to "level" and the word to
 * "level_name". Loki's level detection reads "level" and expects a word, so
 * every line ended up as "unknown". Here "level" holds the lowercase level
 * word, the number moves to "level_value", and "level_name" is kept unchanged
 * so existing relabeling rules keep working during a migration.
 *
 * "service", "env" and the configured extra fields are written to the top of
 * every line so a line copied out of its stream still says where it came
 * from. Loki labels should still come from container labels: non-JSON
 * process output (supervisord, php-fpm) never passes through this formatter.
 *
 * Stack traces are enabled by default. Monolog's JsonFormatter drops the
 * "trace" key unless told otherwise, and Laravel instantiates formatters
 * without arguments unless "formatter_with" is set, so the default has to
 * live here. Monolog renders each frame as a single "file:line" string, and
 * only records carrying an exception are affected, so the size cost is small.
 *
 * Interpolated SQL is redacted here because every record written to the
 * channel passes through this class: reported exceptions, direct Log calls
 * and queued jobs alike. Fixing it at call sites would miss some of them.
 */
final class LokiJsonFormatter extends JsonFormatter
{
    private readonly bool $redactSql;

    private readonly ?string $service;

    private readonly ?string $env;

    /** @var array<string, scalar|array<mixed>|null> */
    private readonly array $extra;

    /**
     * Parent positions are preserved because Laravel passes "formatter_with"
     * options by name through the container. Null overrides fall back to the
     * observability.logging.* config when an application is running.
     *
     * @param  array<array-key, mixed>|null  $extra
     */
    public function __construct(
        int $batchMode = self::BATCH_MODE_JSON,
        bool $appendNewline = true,
        bool $ignoreEmptyContextAndExtra = false,
        ?bool $includeStacktraces = null,
        ?bool $redactSql = null,
        ?string $service = null,
        ?array $extra = null,
    ) {
        $config = self::config();

        $includeStacktraces ??= self::bool($config?->get('observability.logging.include_stacktraces'), true);
        $this->redactSql = $redactSql ?? self::bool($config?->get('observability.logging.redact_sql'), true);
        $this->service = $service ?? self::string($config?->get('observability.logging.service'));
        $this->env = self::string($config?->get('app.env'));
        $this->extra = self::extra($extra ?? $config?->get('observability.logging.extra'));

        parent::__construct($batchMode, $appendNewline, $ignoreEmptyContextAndExtra, $includeStacktraces);
    }

    /**
     * @return array<array<mixed>|bool|float|int|stdClass|string|null>
     */
    protected function normalizeRecord(LogRecord $record): array
    {
        $normalized = parent::normalizeRecord($record);

        $levelName = $record->level->getName();

        $normalized['level'] = strtolower($levelName);
        $normalized['level_name'] = $levelName;
        $normalized['level_value'] = $record->level->value;
        $normalized['service'] = $this->service;
        $normalized['env'] = $this->env;

        // Core fields win: extra fields can never replace message, level or context.
        $normalized += $this->extra;

        if (! $this->redactSql) {
            return $normalized;
        }

        if (is_string($normalized['message'] ?? null)) {
            $normalized['message'] = SqlMessageRedactor::redact($normalized['message']);
        }

        // After normalization, context and context.exception may each be an
        // array or a stdClass (an empty context becomes stdClass, and caller
        // supplied objects pass through untouched). Array indexing on an
        // object throws, which would lose the log line itself.
        if (isset($normalized['context'])) {
            $context = self::mapKey($normalized['context'], 'exception', self::redactException(...));

            if (is_array($context) || $context instanceof stdClass) {
                $normalized['context'] = $context;
            }
        }

        return $normalized;
    }

    /**
     * Redacts the message of a normalized exception and of every exception in
     * its "previous" chain. Monolog nests the chain recursively, and a wrapped
     * QueryException (domain exception, job wrapper, re-throw) would otherwise
     * leak its SQL and bindings one level down. The depth is bounded by
     * Monolog's own normalization depth limit.
     */
    private static function redactException(mixed $exception): mixed
    {
        $exception = self::mapKey(
            $exception,
            'message',
            static fn (mixed $message): mixed => is_string($message) ? SqlMessageRedactor::redact($message) : $message,
        );

        return self::mapKey($exception, 'previous', self::redactException(...));
    }

    /**
     * Applies $map to the value stored under $key, walking arrays and stdClass
     * objects. Objects are cloned so the caller's instances, which Monolog
     * passes through by reference, are never mutated. Nodes without the key
     * are returned unchanged.
     *
     * @param  callable(mixed): mixed  $map
     */
    private static function mapKey(mixed $node, string $key, callable $map): mixed
    {
        if (is_array($node) && array_key_exists($key, $node)) {
            $node[$key] = $map($node[$key]);

            return $node;
        }

        if ($node instanceof stdClass && property_exists($node, $key)) {
            $copy = clone $node;
            $copy->{$key} = $map($node->{$key});

            return $copy;
        }

        return $node;
    }

    private static function config(): ?Repository
    {
        $container = Container::getInstance();

        if (! $container->bound('config')) {
            return null;
        }

        return $container->make('config');
    }

    private static function bool(mixed $value, bool $default): bool
    {
        return is_bool($value) ? $value : $default;
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Keeps only string-keyed fields whose values JSON-encode predictably.
     *
     * @return array<string, scalar|array<mixed>|null>
     */
    private static function extra(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $extra = [];

        foreach ($value as $key => $item) {
            if (is_string($key) && (is_scalar($item) || is_array($item) || $item === null)) {
                $extra[$key] = $item;
            }
        }

        return $extra;
    }
}
