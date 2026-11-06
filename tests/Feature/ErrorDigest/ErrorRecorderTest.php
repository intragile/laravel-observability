<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Tests\Feature\ErrorDigest;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use IntrAgile\Observability\ErrorDigest\ErrorRecorder;
use IntrAgile\Observability\ErrorDigest\ErrorReport;
use IntrAgile\Observability\ErrorDigest\ExceptionHook;
use IntrAgile\Observability\Tests\TestCase;
use PDOException;
use RuntimeException;

final class ErrorRecorderTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('logging.default', 'null');
        $app['config']->set('observability.error_digest.enabled', true);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');
    }

    private function recorder(): ErrorRecorder
    {
        $recorder = $this->app?->make(ErrorRecorder::class);
        $this->assertInstanceOf(ErrorRecorder::class, $recorder);

        return $recorder;
    }

    public function test_query_exception_message_is_redacted(): void
    {
        $exception = new QueryException(
            'testing',
            'select * from users where email = ?',
            ['a@b.c'],
            new PDOException('SQLSTATE[HY000]: General error: boom'),
        );

        $this->recorder()->record($exception);

        $row = ErrorReport::query()->sole();
        $this->assertSame(QueryException::class, $row->exception_type);
        $this->assertStringContainsString('SQLSTATE[HY000]', $row->message);
        $this->assertStringContainsString('General error: boom', $row->message);
        $this->assertStringNotContainsString('a@b.c', $row->message);
        $this->assertStringNotContainsString('select', $row->message);
        $this->assertSame(sha1(QueryException::class."\n".$row->message), $row->fingerprint);
    }

    public function test_record_stores_type_code_and_fingerprint(): void
    {
        $this->recorder()->record(new RuntimeException('Something broke', 42));
        $this->recorder()->record(new RuntimeException('No code'));

        $rows = ErrorReport::query()->orderBy('id')->get();
        $this->assertCount(2, $rows);

        $first = $rows->first();
        $second = $rows->last();
        $this->assertInstanceOf(ErrorReport::class, $first);
        $this->assertInstanceOf(ErrorReport::class, $second);
        $this->assertSame(RuntimeException::class, $first->exception_type);
        $this->assertSame('Something broke', $first->message);
        $this->assertSame('42', $first->code);
        $this->assertSame(sha1(RuntimeException::class."\nSomething broke"), $first->fingerprint);
        $this->assertNull($first->sent_at);
        $this->assertNotNull($first->created_at);

        $this->assertNull($second->code);
    }

    public function test_record_never_throws_when_table_is_missing(): void
    {
        Schema::drop('observability_error_reports');
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context = []): bool {
                return str_contains($message, 'exception')
                    && ($context['exception'] ?? null) === RuntimeException::class
                    && ! array_key_exists('trace', $context);
            });

        $this->recorder()->record(new RuntimeException('boom'));
    }

    public function test_record_does_nothing_when_digest_is_disabled(): void
    {
        config()->set('observability.error_digest.enabled', false);
        Schema::drop('observability_error_reports');
        Log::shouldReceive('warning')->never();

        $this->recorder()->record(new RuntimeException('boom'));
    }

    public function test_record_skips_insert_when_disabled_and_table_exists(): void
    {
        config()->set('observability.error_digest.enabled', false);

        $this->recorder()->record(new RuntimeException('boom'));

        $this->assertSame(0, ErrorReport::query()->count());
    }

    public function test_reentrant_call_is_ignored(): void
    {
        $recorder = $this->recorder();
        $nestedCalls = 0;

        ErrorReport::creating(function () use ($recorder, &$nestedCalls): void {
            $nestedCalls++;
            $recorder->record(new RuntimeException('nested'));
        });

        try {
            $recorder->record(new RuntimeException('outer'));
        } finally {
            ErrorReport::flushEventListeners();
        }

        $this->assertSame(1, $nestedCalls);
        $this->assertSame(['outer'], ErrorReport::query()->pluck('message')->all());

        $recorder->record(new RuntimeException('after'));
        $this->assertSame(2, ErrorReport::query()->count());
    }

    public function test_exception_hook_records_reported_exception(): void
    {
        $handler = $this->app?->make(ExceptionHandler::class);
        $this->assertInstanceOf(Handler::class, $handler);

        ExceptionHook::register(new Exceptions($handler));

        report(new RuntimeException('boom'));

        $row = ErrorReport::query()->sole();
        $this->assertSame('boom', $row->message);
        $this->assertSame(RuntimeException::class, $row->exception_type);
    }
}
