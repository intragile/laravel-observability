<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Tests\Feature\Checks;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use IntrAgile\Observability\Checks\FailedJobsCheck;
use IntrAgile\Observability\Tests\TestCase;
use Spatie\Health\Enums\Status;

final class FailedJobsCheckTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-06 12:00:00'));
        $this->createFailedJobsTable('failed_jobs');
    }

    public function test_passes_when_no_job_has_failed(): void
    {
        $result = FailedJobsCheck::new()->run();

        $this->assertEquals(Status::ok(), $result->status);
        $this->assertSame('none', $result->shortSummary);
        $this->assertSame(0, $result->meta['recent']);
        $this->assertSame(0, $result->meta['older']);
    }

    public function test_fails_on_recent_failures(): void
    {
        $this->insertFailedJob(Carbon::now()->subMinutes(30), 'App\\Jobs\\SendInvoice');
        $this->insertFailedJob(Carbon::now()->subMinutes(50), 'App\\Jobs\\ProvisionOrder');

        $result = FailedJobsCheck::new()->run();

        $this->assertEquals(Status::failed(), $result->status);
        $this->assertSame('2 recent', $result->shortSummary);
        $this->assertStringStartsWith('2 queued job(s) failed in the last 24 hours.', $result->notificationMessage);
        $this->assertSame(2, $result->meta['recent']);
        $this->assertSame(0, $result->meta['older']);
        $this->assertSame(['App\\Jobs\\SendInvoice', 'App\\Jobs\\ProvisionOrder'], $result->meta['recent_jobs']);
    }

    public function test_old_failures_are_reported_but_pass(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->insertFailedJob(Carbon::now()->subDays(3), 'App\\Jobs\\Old');
        }

        $result = FailedJobsCheck::new()->run();

        $this->assertEquals(Status::ok(), $result->status);
        $this->assertSame(0, $result->meta['recent']);
        $this->assertSame(3, $result->meta['older']);
        $this->assertStringContainsString('3 older still on the table', $result->notificationMessage);
    }

    public function test_window_hours_is_configurable(): void
    {
        $this->insertFailedJob(Carbon::now()->subHours(2), 'App\\Jobs\\Late');

        $result = FailedJobsCheck::new()->windowHours(1)->run();

        $this->assertEquals(Status::ok(), $result->status);
        $this->assertSame(0, $result->meta['recent']);
        $this->assertSame(1, $result->meta['older']);
    }

    public function test_window_hours_rejects_values_below_one(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        FailedJobsCheck::new()->windowHours(0);
    }

    public function test_listed_limits_the_reported_job_names(): void
    {
        $this->insertFailedJob(Carbon::now()->subMinutes(10), 'App\\Jobs\\A');
        $this->insertFailedJob(Carbon::now()->subMinutes(20), 'App\\Jobs\\B');
        $this->insertFailedJob(Carbon::now()->subMinutes(30), 'App\\Jobs\\C');

        $result = FailedJobsCheck::new()->listed(1)->run();

        $this->assertEquals(Status::failed(), $result->status);
        $this->assertSame(['App\\Jobs\\A'], $result->meta['recent_jobs']);
    }

    public function test_uses_configured_failed_jobs_table(): void
    {
        config(['queue.failed.table' => 'obs_failed']);
        $this->createFailedJobsTable('obs_failed');
        $this->insertFailedJob(Carbon::now()->subMinutes(5), 'App\\Jobs\\Custom', 'obs_failed');

        $result = FailedJobsCheck::new()->run();

        $this->assertEquals(Status::failed(), $result->status);
        $this->assertSame(1, $result->meta['recent']);
    }

    public function test_fails_when_table_is_missing(): void
    {
        Schema::drop('failed_jobs');

        $result = FailedJobsCheck::new()->run();

        $this->assertEquals(Status::failed(), $result->status);
        $this->assertSame('unknown', $result->shortSummary);
        $this->assertStringNotContainsString('no such table', $result->notificationMessage);
    }

    public function test_malformed_payload_does_not_break_the_check(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => 'not json at all',
            'exception' => 'RuntimeException: boom',
            'failed_at' => Carbon::now()->subMinutes(5)->format('Y-m-d H:i:s'),
        ]);

        $result = FailedJobsCheck::new()->run();

        $this->assertEquals(Status::failed(), $result->status);
        $this->assertSame(1, $result->meta['recent']);
        $this->assertSame([], $result->meta['recent_jobs']);
    }

    private function createFailedJobsTable(string $table): void
    {
        Schema::create($table, function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    }

    private function insertFailedJob(Carbon $at, string $displayName, string $table = 'failed_jobs'): void
    {
        DB::table($table)->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => (string) json_encode(['displayName' => $displayName, 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call']),
            'exception' => 'RuntimeException: boom',
            'failed_at' => $at->format('Y-m-d H:i:s'),
        ]);
    }
}
