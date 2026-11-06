<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Tests\Feature\Doctor;

use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use IntrAgile\Observability\Doctor\DoctorOptions;
use IntrAgile\Observability\Doctor\Probes\HealthChecksProbe;
use IntrAgile\Observability\Readiness\ReadinessEntry;
use IntrAgile\Observability\Readiness\ReadinessStatus;
use IntrAgile\Observability\Tests\TestCase;
use ReflectionProperty;
use RuntimeException;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;
use Spatie\Health\Health;
use Spatie\Health\ResultStores\InMemoryHealthResultStore;
use Spatie\Health\ResultStores\ResultStore;
use Spatie\Health\ResultStores\StoredCheckResults\StoredCheckResult;
use Spatie\Health\ResultStores\StoredCheckResults\StoredCheckResults;
use Throwable;

final class HealthChecksProbeTest extends TestCase
{
    private const NOW = '2026-11-06 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::NOW));
        $this->resetInMemoryStore();
    }

    protected function tearDown(): void
    {
        $this->resetInMemoryStore();

        parent::tearDown();
    }

    public function test_stored_results_warn_with_class_and_redacted_message_when_the_store_throws(): void
    {
        $entry = $this->stored(new RuntimeException(
            'SQLSTATE[42S02]: Base table not found (Connection: mysql, SQL: select * from health_check_result_history_items where secret = \'hunter2\')'
        ));

        $this->assertSame('Stored health results', $entry->component);
        $this->assertSame(ReadinessStatus::Warning, $entry->status);
        $this->assertStringContainsString(RuntimeException::class, $entry->message);
        $this->assertStringContainsString('Base table not found', $entry->message);
        $this->assertStringNotContainsString('hunter2', $entry->message);
    }

    public function test_stored_results_warn_when_nothing_is_stored(): void
    {
        $entry = $this->stored(null);

        $this->assertSame(ReadinessStatus::Warning, $entry->status);
        $this->assertSame('No stored results yet: health:check has not run (or is still in its warmup).', $entry->message);
    }

    public function test_stored_results_warn_when_the_last_run_is_stale(): void
    {
        $entry = $this->stored($this->results(30, ['Database' => 'ok']));

        $this->assertSame(ReadinessStatus::Warning, $entry->status);
        $this->assertStringContainsString('Last run 30 minutes ago', $entry->message);
        $this->assertStringContainsString('10-minute limit', $entry->message);
    }

    public function test_failing_checks_in_a_stale_run_still_fail(): void
    {
        $entry = $this->stored($this->results(30, ['Database' => 'failed', 'Disk' => 'ok']));

        $this->assertSame(ReadinessStatus::Failed, $entry->status);
        $this->assertStringContainsString('1 of 2 checks failing', $entry->message);
        $this->assertStringContainsString('Last run 30 minutes ago', $entry->message);
        $this->assertStringContainsString('10-minute limit', $entry->message);
    }

    public function test_stale_limit_is_read_from_config(): void
    {
        config(['observability.health.stored_results.stale_minutes' => 60]);

        $entry = $this->stored($this->results(30, ['Database' => 'ok']));

        $this->assertSame(ReadinessStatus::Ok, $entry->status);
        $this->assertSame('1 checks ok, last run 30 minutes ago.', $entry->message);
    }

    public function test_stored_results_fail_and_list_failed_and_crashed_checks(): void
    {
        $entry = $this->stored($this->results(2, [
            'Database' => 'failed',
            'Redis' => 'crashed',
            'Disk' => 'ok',
        ]));

        $this->assertSame(ReadinessStatus::Failed, $entry->status);
        $this->assertStringContainsString('2 of 3 checks failing', $entry->message);
        $this->assertStringContainsString('Database (failed)', $entry->message);
        $this->assertStringContainsString('Redis (crashed)', $entry->message);
        $this->assertStringNotContainsString('Disk', $entry->message);
        $this->assertStringContainsString('Last run 2 minutes ago', $entry->message);
    }

    public function test_failing_check_without_label_is_listed_by_name(): void
    {
        $results = new StoredCheckResults(Carbon::now()->subMinutes(1), new Collection([
            new StoredCheckResult(name: 'database', label: '', status: 'failed'),
        ]));

        $entry = $this->stored($results);

        $this->assertSame(ReadinessStatus::Failed, $entry->status);
        $this->assertStringContainsString('database (failed)', $entry->message);
    }

    public function test_stored_results_warn_when_only_warnings(): void
    {
        $entry = $this->stored($this->results(1, ['Disk' => 'warning', 'Database' => 'ok']));

        $this->assertSame(ReadinessStatus::Warning, $entry->status);
        $this->assertStringContainsString('1 checks warning: Disk (warning)', $entry->message);
        $this->assertStringContainsString('Last run 1 minutes ago', $entry->message);
    }

    public function test_skipped_stored_result_is_not_an_error(): void
    {
        $entry = $this->stored($this->results(1, ['Database' => 'ok', 'Broadcast' => 'skipped']));

        $this->assertSame(ReadinessStatus::Ok, $entry->status);
    }

    public function test_stored_results_ok_summarises_count_and_age(): void
    {
        $this->travelTo(Carbon::parse(self::NOW)->subMinutes(3));
        (new InMemoryHealthResultStore)->save(new Collection([
            $this->checkResult('Database', Result::make()->ok()),
            $this->checkResult('Redis', Result::make()->ok()),
        ]));
        $this->travelTo(Carbon::parse(self::NOW));

        $store = new InMemoryHealthResultStore;
        $entry = (new HealthChecksProbe(new Health, static fn (): ResultStore => $store, config()))->storedResults();

        $this->assertSame(ReadinessStatus::Ok, $entry->status);
        $this->assertSame('2 checks ok, last run 3 minutes ago.', $entry->message);
    }

    public function test_container_resolved_probe_reads_the_bound_result_store(): void
    {
        (new InMemoryHealthResultStore)->save(new Collection([
            $this->checkResult('Database', Result::make()->ok()),
        ]));
        $this->instance(ResultStore::class, new InMemoryHealthResultStore);

        $entry = app(HealthChecksProbe::class)->storedResults();

        $this->assertSame('Stored health results', $entry->component);
        $this->assertSame(ReadinessStatus::Ok, $entry->status);
        $this->assertSame('1 checks ok, last run 0 minutes ago.', $entry->message);
    }

    public function test_throwing_check_fails_with_its_class_and_the_others_still_run(): void
    {
        $ok = new HealthProbeFakeCheck(static fn (): Result => Result::make()->ok('fine'));
        $ok->name('Passing');
        $throwing = new HealthProbeFakeCheck(static fn (): Result => throw new RuntimeException('boom'));
        $throwing->name('Throwing');
        $after = new HealthProbeFakeCheck(static fn (): Result => Result::make()->warning('degraded'));
        $after->name('After');

        $entries = $this->probe([$ok, $throwing, $after], timeout: 5.0);

        $this->assertSame(ReadinessStatus::Ok, $this->entry($entries, 'Passing')->status);
        $this->assertSame(ReadinessStatus::Failed, $this->entry($entries, 'Throwing')->status);
        $this->assertStringContainsString(RuntimeException::class, $this->entry($entries, 'Throwing')->message);
        $this->assertSame(ReadinessStatus::Warning, $this->entry($entries, 'After')->status);
        $this->assertSame(1, $after->runs);
    }

    public function test_slow_check_warns(): void
    {
        $slow = new HealthProbeFakeCheck(static function (): Result {
            usleep(120_000);

            return Result::make()->ok('fine');
        });
        $slow->name('Slow');

        $entry = $this->entry($this->probe([$slow], timeout: 0.05), 'Slow');

        $this->assertSame(ReadinessStatus::Warning, $entry->status);
        $this->assertStringContainsString('slower than', $entry->message);
    }

    public function test_failed_result_maps_to_failed(): void
    {
        $failing = new HealthProbeFakeCheck(static fn (): Result => Result::make()->failed('down'));
        $failing->name('Failing');

        $entry = $this->entry($this->probe([$failing], timeout: 5.0), 'Failing');

        $this->assertSame(ReadinessStatus::Failed, $entry->status);
        $this->assertStringContainsString('down', $entry->message);
    }

    public function test_check_whose_condition_is_false_is_skipped(): void
    {
        $conditional = new HealthProbeFakeCheck(static fn (): Result => Result::make()->ok());
        $conditional->name('Conditional')->if(false);

        $entry = $this->entry($this->probe([$conditional], timeout: 5.0), 'Conditional');

        $this->assertSame(ReadinessStatus::Skipped, $entry->status);
        $this->assertSame(0, $conditional->runs);
    }

    public function test_no_registered_checks_warns(): void
    {
        $entries = $this->probe([], timeout: 5.0);

        $this->assertCount(1, $entries);
        $this->assertSame(ReadinessStatus::Warning, $entries[0]->status);
    }

    public function test_probe_is_live(): void
    {
        $this->assertTrue(app(HealthChecksProbe::class)->live());
    }

    /**
     * @param  list<Check>  $checks
     * @return list<ReadinessEntry>
     */
    private function probe(array $checks, float $timeout): array
    {
        $health = new Health;
        $health->checks($checks);

        return (new HealthChecksProbe($health))->run(new DoctorOptions(live: true, sendTest: false, timeout: $timeout));
    }

    private function stored(StoredCheckResults|Throwable|null $source): ReadinessEntry
    {
        $store = new HealthProbeFakeResultStore($source);

        return (new HealthChecksProbe(new Health, static fn (): ResultStore => $store, config()))->storedResults();
    }

    /**
     * @param  array<string, string>  $statuses  label => stored status
     */
    private function results(int $minutesAgo, array $statuses): StoredCheckResults
    {
        $lines = [];

        foreach ($statuses as $label => $status) {
            $lines[] = new StoredCheckResult(name: strtolower($label), label: $label, status: $status);
        }

        return new StoredCheckResults(Carbon::now()->subMinutes($minutesAgo), new Collection($lines));
    }

    private function checkResult(string $label, Result $result): Result
    {
        $check = new HealthProbeFakeCheck(static fn (): Result => $result);
        $check->name($label);

        return $result->check($check);
    }

    private function resetInMemoryStore(): void
    {
        (new ReflectionProperty(InMemoryHealthResultStore::class, 'storedCheckResults'))->setValue(null, null);
    }

    /**
     * @param  list<ReadinessEntry>  $entries
     */
    private function entry(array $entries, string $name): ReadinessEntry
    {
        foreach ($entries as $entry) {
            if (str_ends_with($entry->component, $name)) {
                return $entry;
            }
        }

        $this->fail("No entry for [{$name}].");
    }
}

final class HealthProbeFakeCheck extends Check
{
    public int $runs = 0;

    /**
     * @param  Closure(): Result  $callback
     */
    public function __construct(private readonly Closure $callback)
    {
        parent::__construct();
    }

    public function run(): Result
    {
        $this->runs++;

        return ($this->callback)();
    }
}

final class HealthProbeFakeResultStore implements ResultStore
{
    public function __construct(private readonly StoredCheckResults|Throwable|null $source) {}

    public function save(Collection $checkResults): void {}

    public function latestResults(): ?StoredCheckResults
    {
        if ($this->source instanceof Throwable) {
            throw $this->source;
        }

        return $this->source;
    }
}
