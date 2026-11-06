<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Console;

use Illuminate\Console\Command;
use IntrAgile\Observability\ErrorDigest\ExceptionHook;
use IntrAgile\Observability\Logging\LokiJsonFormatter;
use IntrAgile\Observability\Readiness\Inspector;
use IntrAgile\Observability\Readiness\ReadinessEntry;
use IntrAgile\Observability\Readiness\ReadinessStatus;

/**
 * Publishes the package files and explains what is still left to do by hand.
 *
 * The readiness table only reflects configuration and installed packages. Whether logs actually
 * reach Loki or a broadcast actually reaches a client can only be proven by exercising the chain,
 * which is why the command ends by pointing at the live doctor run.
 */
final class InstallCommand extends Command
{
    private const MIGRATION_SUFFIX = 'create_observability_error_reports_table.php';

    /** @var string */
    protected $signature = 'observability:install
        {--force : Overwrite files that were published before}
        {--strict : Exit with a non-zero status when the readiness check reports a warning}';

    /** @var string */
    protected $description = 'Publish the observability config and report what is left to set up';

    public function handle(Inspector $inspector): int
    {
        $this->publish('observability-config');

        if (config('observability.error_digest.enabled')) {
            $this->publishMigration();
        }

        $entries = $inspector->inspect();

        $this->newLine();
        $this->info('Readiness');
        $this->table(
            ['Component', 'Status', 'Details'],
            array_map(static fn (ReadinessEntry $entry): array => [$entry->component, $entry->status->value, $entry->message], $entries),
        );

        $this->printNextSteps();

        $this->newLine();
        $this->line('Verify the whole chain end to end with: <comment>php artisan observability:doctor --live</comment>');

        $hasProblems = array_filter(
            $entries,
            static fn (ReadinessEntry $entry): bool => in_array($entry->status, [ReadinessStatus::Warning, ReadinessStatus::Failed], true),
        ) !== [];

        if ($hasProblems && $this->option('strict')) {
            $this->error('Readiness warnings found and --strict is set.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function publish(string $tag): void
    {
        $this->call('vendor:publish', array_filter([
            '--tag' => $tag,
            '--force' => (bool) $this->option('force'),
        ]));
    }

    /**
     * Laravel renames published migrations to the current timestamp, so the usual "file already
     * exists" protection of vendor:publish never triggers and a second run would add a duplicate
     * that fails on migrate. An existing copy under any timestamp therefore counts as published.
     */
    private function publishMigration(): void
    {
        $existing = glob($this->laravel->databasePath('migrations/*_'.self::MIGRATION_SUFFIX));

        if (is_array($existing) && $existing !== []) {
            $this->line('Error report migration already published: '.basename($existing[0]));

            return;
        }

        $this->publish('observability-migrations');
    }

    private function printNextSteps(): void
    {
        $formatter = LokiJsonFormatter::class;
        $hook = ExceptionHook::class;

        $this->newLine();
        $this->info('Next steps');
        $this->line('1. Add a JSON channel to config/logging.php and include it in your stack:');
        $this->line(<<<PHP

                'json' => [
                    'driver' => 'monolog',
                    'handler' => Monolog\\Handler\\StreamHandler::class,
                    'with' => ['stream' => 'php://stderr'],
                    'formatter' => \\{$formatter}::class,
                ],

            PHP);
        $this->line('2. Record reported exceptions for the error digest in bootstrap/app.php:');
        $this->line(<<<PHP

                ->withExceptions(function (Illuminate\\Foundation\\Configuration\\Exceptions \$exceptions) {
                    \\{$hook}::register(\$exceptions);
                })

            PHP);
    }
}
