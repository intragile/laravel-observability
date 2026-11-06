<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Tests\Feature\Doctor;

use Closure;
use IntrAgile\Observability\Doctor\DoctorOptions;
use IntrAgile\Observability\Doctor\Probes\HealthChecksProbe;
use IntrAgile\Observability\Readiness\ReadinessEntry;
use IntrAgile\Observability\Readiness\ReadinessStatus;
use IntrAgile\Observability\Tests\TestCase;
use RuntimeException;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;
use Spatie\Health\Health;

final class HealthChecksProbeTest extends TestCase
{
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
