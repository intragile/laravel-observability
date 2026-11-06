<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Tests\Feature;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Testing\PendingCommand;
use IntrAgile\Observability\Logging\LokiJsonFormatter;
use IntrAgile\Observability\Tests\TestCase;
use Orchestra\Testbench\Attributes\DefineEnvironment;

final class InstallCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->cleanPublishedFiles();
    }

    protected function tearDown(): void
    {
        $this->cleanPublishedFiles();

        parent::tearDown();
    }

    public function test_config_is_published(): void
    {
        $this->install()->assertExitCode(0)->run();

        $this->assertFileExists(config_path('observability.php'));
        $this->assertSame([], $this->publishedMigrations(), 'The migration is only needed when the digest is enabled.');
    }

    #[DefineEnvironment('enableDigest')]
    public function test_migration_is_published_when_digest_is_enabled(): void
    {
        $this->install()->assertExitCode(0)->run();

        $this->assertCount(1, $this->publishedMigrations());
    }

    #[DefineEnvironment('enableDigest')]
    public function test_running_twice_does_not_duplicate_the_migration(): void
    {
        $this->install()->assertExitCode(0)->run();
        $this->install(['--force' => true])->assertExitCode(0)->run();

        $this->assertCount(1, $this->publishedMigrations());
    }

    public function test_readiness_rows_and_next_steps_are_printed(): void
    {
        $this->install()
            ->expectsOutputToContain('SchemaCompleteCheck')
            ->expectsOutputToContain('Structured logging')
            ->expectsOutputToContain('Error digest')
            ->expectsOutputToContain(LokiJsonFormatter::class)
            ->expectsOutputToContain('ExceptionHook::register')
            ->expectsOutputToContain('php artisan observability:doctor --live')
            ->assertExitCode(0)
            ->run();
    }

    public function test_strict_mode_fails_on_warning(): void
    {
        // No channel uses the Loki formatter, which is a warning.
        config()->set('logging.channels', ['single' => ['driver' => 'single']]);

        $this->install(['--strict' => true])->assertExitCode(1)->run();
    }

    public function test_warnings_do_not_fail_without_strict_mode(): void
    {
        config()->set('logging.channels', ['single' => ['driver' => 'single']]);

        $this->install()->assertExitCode(0)->run();
    }

    public function test_strict_mode_passes_without_warnings(): void
    {
        config()->set('logging.channels', ['json' => ['driver' => 'monolog', 'formatter' => LokiJsonFormatter::class]]);
        config()->set('observability.prometheus.basic_auth.password', 's3cret');
        config()->set('cache.default', 'file');
        config()->set('observability.error_digest.enabled', false);

        $this->install(['--strict' => true])->assertExitCode(0)->run();
    }

    /**
     * @param  Application  $app
     */
    protected function enableDigest($app): void
    {
        $app->make('config')->set('observability.error_digest.enabled', true);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function install(array $options = []): PendingCommand
    {
        $command = $this->artisan('observability:install', $options);
        $this->assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }

    /**
     * @return list<string>
     */
    private function publishedMigrations(): array
    {
        $files = glob(database_path('migrations/*_create_observability_error_reports_table.php'));

        return $files === false ? [] : $files;
    }

    private function cleanPublishedFiles(): void
    {
        foreach ([config_path('observability.php'), ...$this->publishedMigrations()] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
}
