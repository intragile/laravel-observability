<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Checks;

use InvalidArgumentException;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;
use Throwable;

/**
 * Fails while any migration file has not been applied to the database.
 *
 * Connectivity checks stay green on a half-migrated schema: a `select 1` succeeds and heartbeat
 * checks read the cache, so none of them touches the tables that are missing. A schema can also
 * become incomplete after a healthy start (an interrupted `migrate`, a database backup restored
 * under newer code, a failed rolling update), so this check asserts the state of the running
 * system continuously rather than only at boot.
 *
 * It asks the Migrator directly instead of parsing `migrate:status` output: the same two inputs
 * (migration files and the `migrations` table) without text parsing or ANSI colouring pitfalls.
 *
 * Fail-closed: a missing `migrations` table, or a failure to answer the question at all (for
 * example an unreachable database), is never reported as "nothing pending".
 */
final class SchemaCompleteCheck extends Check
{
    /** Maximum number of pending migrations named in the message; the rest are summarised. */
    private int $listed = 5;

    /** @var list<string> */
    private array $paths = [];

    public function listed(int $count): static
    {
        if ($count < 0) {
            throw new InvalidArgumentException('The listed count must not be negative.');
        }

        $this->listed = $count;

        return $this;
    }

    /**
     * Additional migration directories to compare against the `migrations` table.
     *
     * @param  array<array-key, mixed>  $paths
     */
    public function paths(array $paths): static
    {
        $this->paths = array_values(array_filter($paths, is_string(...)));

        return $this;
    }

    public function run(): Result
    {
        $result = Result::make();

        try {
            ['total' => $total, 'pending' => $pending] = $this->pendingMigrations();
        } catch (Throwable $exception) {
            // The exception text is deliberately withheld: a connection error may carry the
            // database host and user, and the health endpoint is read more widely than the logs.
            return $result
                ->shortSummary('unknown')
                ->failed('The schema state could not be determined ('.$exception::class.'); treating it as incomplete.');
        }

        if ($total === null) {
            return $result
                ->shortSummary('no table')
                ->failed('The "migrations" table does not exist; the database was never migrated.');
        }

        $result->shortSummary(($total - count($pending)).'/'.$total);

        if ($pending === []) {
            return $result->ok();
        }

        $message = count($pending).' migration(s) have not run';

        if ($this->listed > 0) {
            $message .= ': '.implode(', ', array_slice($pending, 0, $this->listed));
        }

        if (count($pending) > $this->listed) {
            $message .= ' (+'.(count($pending) - $this->listed).' more)';
        }

        return $result->failed($message.'.');
    }

    /**
     * Names of the migrations that have not run.
     *
     * `total` is the number of migration files found, or null when the `migrations` table is absent.
     *
     * @return array{total: int|null, pending: list<string>}
     */
    private function pendingMigrations(): array
    {
        $migrator = app('migrator');

        if (! $migrator->repositoryExists()) {
            return ['total' => null, 'pending' => []];
        }

        // `paths()` only holds directories registered through `loadMigrationsFrom()`. The framework
        // adds the application's own directory by hand in its migrate commands, so it is added here
        // too; without it every application migration would be invisible and the check silently green.
        $paths = array_values(array_unique(array_merge(
            $migrator->paths(),
            $this->paths,
            [database_path('migrations')],
        )));

        // Keys are migration names, so they compare directly with the rows of the `migrations` table.
        $files = $migrator->getMigrationFiles($paths);

        $ran = array_filter($migrator->getRepository()->getRan(), is_string(...));

        $pending = array_values(array_diff(
            array_map(strval(...), array_keys($files)),
            $ran,
        ));

        return ['total' => count($files), 'pending' => $pending];
    }
}
