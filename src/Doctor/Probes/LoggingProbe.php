<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Doctor\Probes;

use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\QueryException;
use Illuminate\Log\Logger as IlluminateLogger;
use Illuminate\Log\LogManager;
use IntrAgile\Observability\Doctor\DoctorOptions;
use IntrAgile\Observability\Doctor\Probe;
use IntrAgile\Observability\Readiness\ReadinessEntry;
use IntrAgile\Observability\Readiness\ReadinessStatus;
use Monolog\Formatter\FormatterInterface;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\Level;
use Monolog\Logger as MonologLogger;
use Monolog\LogRecord;
use PDOException;
use Throwable;

/**
 * Pushes a synthetic error record through the formatters of the channels the application really
 * logs to, and checks what would be written.
 *
 * The record carries a genuine QueryException whose SQL binding is a unique marker, both in the
 * message and as the context exception, the way Laravel reports a failed query. Seeing the marker
 * in the output proves the SQL literals would reach the log store. Nothing is written: only the
 * formatter is invoked.
 */
final class LoggingProbe implements Probe
{
    private const MARKER = 'observability-doctor-7c1e9a';

    private const REQUIRED_FIELDS = ['level', 'service', 'env'];

    public function __construct(
        private readonly Repository $config,
        private readonly LogManager $log,
    ) {}

    public function name(): string
    {
        return 'Structured logging probe';
    }

    public function live(): bool
    {
        return false;
    }

    public function run(DoctorOptions $options): array
    {
        $default = $this->config->get('logging.default');

        if (! is_string($default) || $default === '') {
            return [new ReadinessEntry('Logging', ReadinessStatus::Warning, 'logging.default is not set; there is no channel to probe.')];
        }

        $entries = [];

        foreach ($this->channelNames($default, []) as $channel) {
            $entries[] = $this->probeChannel($channel);
        }

        return $entries;
    }

    /**
     * Expands stack channels into the channels they write to, since each has its own formatter.
     *
     * @param  list<string>  $seen  guards against stacks that include each other
     * @return list<string>
     */
    private function channelNames(string $channel, array $seen): array
    {
        $config = $this->config->get("logging.channels.{$channel}");

        if (! is_array($config) || ($config['driver'] ?? null) !== 'stack' || in_array($channel, $seen, true)) {
            return [$channel];
        }

        $names = [];

        foreach (is_array($config['channels'] ?? null) ? $config['channels'] : [] as $child) {
            if (is_string($child) && $child !== '') {
                array_push($names, ...$this->channelNames($child, [...$seen, $channel]));
            }
        }

        return array_values(array_unique($names));
    }

    private function probeChannel(string $channel): ReadinessEntry
    {
        $component = "Logging channel [{$channel}]";

        try {
            $formatters = $this->formatters($channel);
        } catch (Throwable $exception) {
            return new ReadinessEntry($component, ReadinessStatus::Failed, 'The channel could not be created ('.$exception::class.').');
        }

        if ($formatters === []) {
            return new ReadinessEntry($component, ReadinessStatus::Warning, 'The channel has no handler with a formatter, so nothing it logs can be checked.');
        }

        $worst = null;

        foreach ($formatters as $formatter) {
            $entry = $this->probeFormatter($component, $formatter);

            if ($worst === null || self::rank($entry->status) > self::rank($worst->status)) {
                $worst = $entry;
            }
        }

        return $worst;
    }

    /**
     * @return list<FormatterInterface>
     */
    private function formatters(string $channel): array
    {
        $logger = $this->log->channel($channel);
        $monolog = $logger instanceof IlluminateLogger ? $logger->getLogger() : $logger;

        if (! $monolog instanceof MonologLogger) {
            return [];
        }

        $formatters = [];

        foreach ($monolog->getHandlers() as $handler) {
            if ($handler instanceof FormattableHandlerInterface) {
                $formatters[] = $handler->getFormatter();
            }
        }

        return $formatters;
    }

    private function probeFormatter(string $component, FormatterInterface $formatter): ReadinessEntry
    {
        $class = $formatter::class;

        try {
            $output = $formatter->format($this->record());
        } catch (Throwable $exception) {
            return new ReadinessEntry($component, ReadinessStatus::Failed, "{$class} threw ".$exception::class.' while formatting a record.');
        }

        $output = is_string($output) ? $output : (string) json_encode($output);
        $leaks = str_contains($output, self::MARKER);

        // A plain-text formatter is a choice, not a breakage: report it, but do not fail the run.
        if (! $formatter instanceof JsonFormatter) {
            return new ReadinessEntry($component, ReadinessStatus::Warning, "{$class} is not structured: lines are plain text, so Loki "
                .'cannot extract level, service or env.'.($leaks ? ' SQL literals from query exceptions are not redacted either.' : ''));
        }

        $decoded = json_decode(trim($output), true);

        if (! is_array($decoded)) {
            return new ReadinessEntry($component, ReadinessStatus::Failed, "{$class} did not produce valid JSON.");
        }

        $problems = [];
        $missing = array_values(array_filter(
            self::REQUIRED_FIELDS,
            static fn (string $field): bool => ! isset($decoded[$field]) || $decoded[$field] === '',
        ));

        if ($missing !== []) {
            $problems[] = 'the output lacks '.implode(', ', $missing);
        } elseif (! is_string($decoded['level'])) {
            $problems[] = 'level is not a word, so Loki reads every line as level "unknown"';
        }

        if ($leaks) {
            $problems[] = 'an SQL literal from a query exception is visible; enable observability.logging.redact_sql';
        }

        if ($problems !== []) {
            return new ReadinessEntry($component, ReadinessStatus::Failed, "{$class}: ".implode('; ', $problems).'.');
        }

        return new ReadinessEntry($component, ReadinessStatus::Ok, "{$class} writes JSON with level, service and env, and redacts SQL literals.");
    }

    private function record(): LogRecord
    {
        $exception = new QueryException(
            'doctor',
            'select * from observability_doctor where secret = ?',
            [self::MARKER],
            new PDOException('SQLSTATE[HY000]: synthetic failure raised by observability:doctor'),
        );

        return new LogRecord(
            new DateTimeImmutable,
            'observability-doctor',
            Level::Error,
            $exception->getMessage(),
            ['exception' => $exception],
        );
    }

    private static function rank(ReadinessStatus $status): int
    {
        return match ($status) {
            ReadinessStatus::Skipped, ReadinessStatus::Ok => 0,
            ReadinessStatus::Warning => 1,
            ReadinessStatus::Failed => 2,
        };
    }
}
