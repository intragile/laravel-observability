<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Tests\Feature\ErrorDigest;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\PendingCommand;
use IntrAgile\Observability\Console\ErrorDigestCommand;
use IntrAgile\Observability\ErrorDigest\ErrorReport;
use IntrAgile\Observability\Tests\TestCase;
use RuntimeException;

final class ErrorDigestCommandTest extends TestCase
{
    private const WEBHOOK = 'https://hooks.slack.test/services/T000/B000/XXX';

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('observability.error_digest.webhook_url', self::WEBHOOK);
        $app['config']->set('observability.error_digest.instance', 'demo');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $kernel = $this->app?->make(Kernel::class);
        $this->assertInstanceOf(Kernel::class, $kernel);
        $command = $this->app?->make(ErrorDigestCommand::class);
        $this->assertInstanceOf(ErrorDigestCommand::class, $command);
        $kernel->registerCommand($command);

        Http::preventStrayRequests();
    }

    private function report(string $message, ?Carbon $createdAt = null, ?Carbon $sentAt = null): ErrorReport
    {
        $report = new ErrorReport;
        $report->exception_type = RuntimeException::class;
        $report->message = $message;
        $report->code = null;
        $report->fingerprint = sha1(RuntimeException::class."\n".$message);
        $report->sent_at = $sentAt;
        $report->created_at = $createdAt ?? Carbon::now();
        $report->save();

        return $report;
    }

    private function digest(): PendingCommand
    {
        $command = $this->artisan('observability:error-digest');
        $this->assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }

    private function unsentCount(): int
    {
        return ErrorReport::query()->whereNull('sent_at')->count();
    }

    public function test_success_marks_all_claimed_rows(): void
    {
        Http::fake([self::WEBHOOK => Http::response('ok', 200)]);
        $this->report('first');
        $this->report('first');
        $this->report('second');

        $this->digest()->assertExitCode(0);

        $this->assertSame(0, $this->unsentCount());
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            $body = $request->body();

            return $request->url() === self::WEBHOOK
                && str_contains($body, 'first')
                && str_contains($body, 'second')
                && str_contains($body, 'demo');
        });
    }

    public function test_rows_stay_unsent_when_slack_fails(): void
    {
        // Stubs registered by a later Http::fake() call do not override earlier ones, so use a sequence.
        Http::fake([self::WEBHOOK => Http::sequence()
            ->push('invalid_payload', 500)
            ->push('ok', 200)]);
        $this->report('first');
        $this->report('second');

        $this->digest()->assertExitCode(1);

        $this->assertSame(2, $this->unsentCount());

        $this->digest()->assertExitCode(0);

        $this->assertSame(0, $this->unsentCount());
        Http::assertSentCount(2);
    }

    public function test_rows_stay_unsent_when_connection_fails(): void
    {
        Http::fake(function (): never {
            throw new ConnectionException('Connection refused');
        });
        $this->report('first');

        $this->digest()->assertExitCode(1);

        $this->assertSame(1, $this->unsentCount());
    }

    public function test_only_claimed_ids_are_marked(): void
    {
        $this->report('first');
        $this->report('second');

        Http::fake(function (): mixed {
            $this->report('arrived during delivery');

            return Http::response('ok', 200);
        });

        $this->digest()->assertExitCode(0);

        $unsent = ErrorReport::query()->whereNull('sent_at')->pluck('message')->all();
        $this->assertSame(['arrived during delivery'], $unsent);
        $this->assertSame(2, ErrorReport::query()->whereNotNull('sent_at')->count());
    }

    public function test_batch_size_limits_claimed_rows(): void
    {
        config()->set('observability.error_digest.batch_size', 2);
        Http::fake([self::WEBHOOK => Http::response('ok', 200)]);
        $first = $this->report('first');
        $second = $this->report('second');
        $third = $this->report('third');

        $this->digest()->assertExitCode(0);

        $this->assertNotNull($first->refresh()->sent_at);
        $this->assertNotNull($second->refresh()->sent_at);
        $this->assertNull($third->refresh()->sent_at);
    }

    public function test_nothing_to_send_makes_no_http_call(): void
    {
        Http::fake();

        $this->digest()->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_empty_webhook_sends_nothing_and_exits_zero(): void
    {
        config()->set('observability.error_digest.webhook_url', '  ');
        Http::fake();
        $this->report('first');

        $this->digest()
            ->expectsOutputToContain('webhook')
            ->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertSame(1, $this->unsentCount());
    }

    public function test_retention_deletes_old_sent_rows(): void
    {
        config()->set('observability.error_digest.retention_days', 30);
        Http::fake();
        $oldSent = $this->report('old sent', Carbon::now()->subDays(31), Carbon::now()->subDays(31));
        $recentSent = $this->report('recent sent', Carbon::now()->subDays(5), Carbon::now()->subDays(5));
        $oldUnsent = $this->report('old unsent', Carbon::now()->subDays(40));
        config()->set('observability.error_digest.webhook_url', null);

        $this->digest()->assertExitCode(0);

        $this->assertFalse(ErrorReport::query()->whereKey($oldSent->id)->exists());
        $this->assertTrue(ErrorReport::query()->whereKey($recentSent->id)->exists());
        $this->assertTrue(ErrorReport::query()->whereKey($oldUnsent->id)->exists());
    }

    public function test_retention_runs_after_successful_delivery(): void
    {
        Http::fake([self::WEBHOOK => Http::response('ok', 200)]);
        $oldSent = $this->report('old sent', Carbon::now()->subDays(31), Carbon::now()->subDays(31));
        $this->report('fresh');

        $this->digest()->assertExitCode(0);

        $this->assertFalse(ErrorReport::query()->whereKey($oldSent->id)->exists());
        $this->assertSame(1, ErrorReport::query()->count());
    }

    public function test_skips_when_another_run_holds_the_lock(): void
    {
        Http::fake();
        $this->report('first');

        $lock = Cache::lock(ErrorDigestCommand::LOCK_NAME, 60);
        $this->assertTrue($lock->get());

        try {
            $this->digest()->assertExitCode(0);
        } finally {
            $lock->release();
        }

        Http::assertNothingSent();
        $this->assertSame(1, $this->unsentCount());
    }
}
