<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Tests\Feature\Doctor;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\PendingCommand;
use IntrAgile\Observability\Doctor\DoctorOptions;
use IntrAgile\Observability\Doctor\DoctorRunner;
use IntrAgile\Observability\Doctor\Probe;
use IntrAgile\Observability\Doctor\Probes\BroadcastProbe;
use IntrAgile\Observability\Doctor\Probes\ErrorDigestProbe;
use IntrAgile\Observability\Doctor\Probes\HealthChecksProbe;
use IntrAgile\Observability\Doctor\Probes\LoggingProbe;
use IntrAgile\Observability\Doctor\Probes\PrometheusProbe;
use IntrAgile\Observability\Doctor\Probes\SchedulingProbe;
use IntrAgile\Observability\Logging\LokiJsonFormatter;
use IntrAgile\Observability\Readiness\Inspector;
use IntrAgile\Observability\Readiness\ReadinessEntry;
use IntrAgile\Observability\Readiness\ReadinessStatus;
use IntrAgile\Observability\Tests\TestCase;
use RuntimeException;

final class DoctorCommandTest extends TestCase
{
    /**
     * Configures an application whose static inspection reports no warnings, so the fake probes
     * alone decide the outcome.
     *
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $config = $app->make('config');

        foreach (['schema_complete', 'failed_jobs', 'mail_relay', 'request_timing', 'backup_freshness', 'broadcast', 'container_memory'] as $check) {
            $config->set("observability.health.checks.{$check}.enabled", false);
        }

        $config->set('observability.prometheus.basic_auth.password', 's3cret');
        $config->set('logging.channels.json', ['driver' => 'monolog', 'formatter' => LokiJsonFormatter::class]);
    }

    public function test_json_output_is_valid_and_reports_the_worst_status(): void
    {
        $this->useProbes(new DoctorFakeProbe('Static', ReadinessStatus::Warning));

        $exitCode = Artisan::call('observability:doctor', ['--json' => true]);
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertIsArray($report);
        $this->assertSame(['entries', 'worst'], array_keys($report));
        $this->assertSame('warning', $report['worst']);

        $entries = $report['entries'];
        $this->assertIsArray($entries);
        $first = reset($entries);
        $last = end($entries);
        $this->assertIsArray($first);
        $this->assertIsArray($last);

        $this->assertSame(['component', 'status', 'message', 'duration_ms'], array_keys($last));
        $this->assertSame('Static', $last['component']);
        $this->assertSame('warning', $last['status']);
        $this->assertIsNumeric($last['duration_ms']);
        $this->assertNull($first['duration_ms']);
    }

    public function test_failed_entry_exits_with_one(): void
    {
        $this->useProbes(new DoctorFakeProbe('Static', ReadinessStatus::Failed));

        $this->doctor()->assertExitCode(1);
    }

    public function test_warning_exits_with_zero_without_strict(): void
    {
        $this->useProbes(new DoctorFakeProbe('Static', ReadinessStatus::Warning));

        $this->doctor()->assertExitCode(0);
    }

    public function test_warning_exits_with_one_with_strict(): void
    {
        $this->useProbes(new DoctorFakeProbe('Static', ReadinessStatus::Warning));

        $this->doctor(['--strict' => true])->assertExitCode(1);
    }

    public function test_clean_run_exits_with_zero_even_with_strict(): void
    {
        $this->useProbes(new DoctorFakeProbe('Static', ReadinessStatus::Ok));

        $this->doctor(['--strict' => true])
            ->expectsOutputToContain('Static')
            ->assertExitCode(0);
    }

    public function test_live_probes_only_run_with_live(): void
    {
        $static = new DoctorFakeProbe('Static', ReadinessStatus::Ok);
        $live = new DoctorFakeProbe('Live', ReadinessStatus::Ok, live: true);
        $this->useProbes($static, $live);

        $this->doctor()->assertExitCode(0);
        $this->assertSame(1, $static->runs);
        $this->assertSame(0, $live->runs);

        $this->doctor(['--live' => true])->assertExitCode(0);
        $this->assertSame(2, $static->runs);
        $this->assertSame(1, $live->runs);
    }

    public function test_options_reach_the_probes(): void
    {
        $probe = new DoctorFakeProbe('Static', ReadinessStatus::Ok);
        $this->useProbes($probe);

        $this->doctor(['--send-test' => true, '--timeout' => '2.5'])->assertExitCode(0);

        $this->assertEquals(new DoctorOptions(live: false, sendTest: true, timeout: 2.5), $probe->options);
    }

    public function test_invalid_timeout_is_rejected(): void
    {
        $probe = new DoctorFakeProbe('Static', ReadinessStatus::Ok);
        $this->useProbes($probe);

        $this->doctor(['--timeout' => 'soon'])->assertExitCode(2);
        $this->assertSame(0, $probe->runs);
    }

    public function test_throwing_probe_becomes_a_failed_entry_and_the_run_continues(): void
    {
        $throwing = new DoctorFakeProbe('Throwing', ReadinessStatus::Ok, throws: true);
        $after = new DoctorFakeProbe('After', ReadinessStatus::Ok);

        $report = (new DoctorRunner(app(Inspector::class), [$throwing, $after]))
            ->run(new DoctorOptions(live: false, sendTest: false, timeout: 5.0));

        $entries = $report->entries();
        $failed = array_values(array_filter($entries, static fn (ReadinessEntry $entry): bool => $entry->component === 'Throwing'));

        $this->assertCount(1, $failed);
        $this->assertSame(ReadinessStatus::Failed, $failed[0]->status);
        $this->assertStringContainsString(RuntimeException::class, $failed[0]->message);
        $this->assertSame(1, $after->runs);
        $this->assertSame(ReadinessStatus::Failed, $report->worst());
    }

    public function test_static_inspection_entries_come_first(): void
    {
        $report = (new DoctorRunner(app(Inspector::class), [new DoctorFakeProbe('Static', ReadinessStatus::Ok)]))
            ->run(new DoctorOptions(live: false, sendTest: false, timeout: 5.0));

        $components = array_map(static fn (ReadinessEntry $entry): string => $entry->component, $report->entries());

        $this->assertSame('Health registration', $components[0]);
        $this->assertSame('Static', $components[array_key_last($components)]);
    }

    public function test_default_runner_marks_only_network_probes_as_live(): void
    {
        $live = [];

        foreach (app(DoctorRunner::class)->probes() as $probe) {
            $live[$probe::class] = $probe->live();
        }

        $this->assertSame([
            LoggingProbe::class => false,
            SchedulingProbe::class => false,
            PrometheusProbe::class => false,
            ErrorDigestProbe::class => false,
            HealthChecksProbe::class => true,
            BroadcastProbe::class => true,
        ], $live);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function doctor(array $options = []): PendingCommand
    {
        $command = $this->artisan('observability:doctor', $options);
        $this->assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }

    private function useProbes(Probe ...$probes): void
    {
        app()->instance(DoctorRunner::class, new DoctorRunner(app(Inspector::class), array_values($probes)));
    }
}

final class DoctorFakeProbe implements Probe
{
    public int $runs = 0;

    public ?DoctorOptions $options = null;

    public function __construct(
        private readonly string $name,
        private readonly ReadinessStatus $status,
        private readonly bool $live = false,
        private readonly bool $throws = false,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function live(): bool
    {
        return $this->live;
    }

    public function run(DoctorOptions $options): array
    {
        $this->runs++;
        $this->options = $options;

        if ($this->throws) {
            throw new RuntimeException('probe exploded');
        }

        return [new ReadinessEntry($this->name, $this->status, 'Fake result.')];
    }
}
