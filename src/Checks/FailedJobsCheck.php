<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Checks;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;
use Throwable;

/**
 * Fails when queued jobs have failed recently, read from Laravel's own failed-jobs table.
 *
 * A job that exhausts its tries simply stops: nothing else in the request path notices, and the
 * work it was meant to do never happens. Without this check that failure mode is silent.
 *
 * Only failures inside the window decide the verdict; older ones are reported, not judged. A single
 * failure nobody cleared would otherwise hold the check red forever, and a check that is expected
 * to be red is one people learn to ignore. The older count stays in the message and the meta so
 * nothing disappears by ageing.
 */
final class FailedJobsCheck extends Check
{
    /** How recent a failure has to be to raise the alarm rather than merely be counted. */
    private int $windowHours = 24;

    /** Maximum number of recent job names reported. */
    private int $listed = 5;

    public function windowHours(int $hours): static
    {
        if ($hours < 1) {
            throw new InvalidArgumentException('The window must be at least one hour.');
        }

        $this->windowHours = $hours;

        return $this;
    }

    public function listed(int $count): static
    {
        if ($count < 0) {
            throw new InvalidArgumentException('The listed count must not be negative.');
        }

        $this->listed = $count;

        return $this;
    }

    public function run(): Result
    {
        $result = Result::make();

        try {
            $table = config('queue.failed.table', 'failed_jobs');
            $table = is_string($table) && $table !== '' ? $table : 'failed_jobs';

            // Laravel writes failed jobs to this connection, which need not be the default one.
            $connection = config('queue.failed.database');
            $db = DB::connection(is_string($connection) && $connection !== '' ? $connection : null);

            $since = Carbon::now()->subHours($this->windowHours);

            $recent = $db->table($table)->where('failed_at', '>=', $since)->count();
            $older = $db->table($table)->where('failed_at', '<', $since)->count();

            $names = [];

            if ($recent > 0 && $this->listed > 0) {
                $payloads = $db->table($table)
                    ->where('failed_at', '>=', $since)
                    ->orderByDesc('failed_at')
                    ->orderByDesc('id')
                    ->limit($this->listed)
                    ->pluck('payload')
                    ->all();

                $names = $this->displayNames($payloads);
            }
        } catch (Throwable $exception) {
            // The exception text is withheld: database errors may expose hosts, users or schema
            // details, and the health endpoint is read more widely than the logs.
            return $result
                ->shortSummary('unknown')
                ->failed('The failed-jobs table could not be read ('.$exception::class.').');
        }

        $result
            ->shortSummary($recent > 0 ? $recent.' recent' : 'none')
            ->meta(['recent' => $recent, 'older' => $older, 'recent_jobs' => $names]);

        $trailer = $older > 0 ? ' ('.$older.' older still on the table.)' : '';

        if ($recent > 0) {
            $latest = $names !== [] ? ' Latest: '.implode(', ', $names).'.' : '';

            return $result->failed(
                $recent.' queued job(s) failed in the last '.$this->windowHours.' hours.'.$latest.$trailer
            );
        }

        if ($older > 0) {
            return $result->ok('No queued job has failed in the last '.$this->windowHours.' hours.'.$trailer);
        }

        return $result->ok();
    }

    /**
     * Distinct job display names from raw queue payloads; unreadable payloads are skipped.
     *
     * @param  array<array-key, mixed>  $payloads
     * @return list<string>
     */
    private function displayNames(array $payloads): array
    {
        $names = [];

        foreach ($payloads as $payload) {
            if (! is_string($payload)) {
                continue;
            }

            $decoded = json_decode($payload, true);

            if (is_array($decoded) && isset($decoded['displayName']) && is_string($decoded['displayName'])) {
                $names[] = $decoded['displayName'];
            }
        }

        return array_values(array_unique($names));
    }
}
