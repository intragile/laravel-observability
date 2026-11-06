<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Tests\Feature\Checks;

use Illuminate\Database\Migrations\MigrationRepositoryInterface;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Artisan;
use IntrAgile\Observability\Checks\SchemaCompleteCheck;
use IntrAgile\Observability\Tests\TestCase;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Spatie\Health\Enums\Status;

final class SchemaCompleteCheckTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/obs-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->dir);

        parent::tearDown();
    }

    public function test_passes_when_every_migration_has_run(): void
    {
        $this->writeMigrations(2);
        $this->assertSame(0, Artisan::call('migrate', ['--path' => $this->dir, '--realpath' => true]));

        $result = SchemaCompleteCheck::new()->paths([$this->dir])->run();

        $this->assertEquals(Status::ok(), $result->status);
        $this->assertSame('2/2', $result->shortSummary);
    }

    public function test_fails_and_lists_at_most_five_pending_migrations(): void
    {
        $this->assertSame(0, Artisan::call('migrate:install'));
        $names = $this->writeMigrations(7);

        $result = SchemaCompleteCheck::new()->paths([$this->dir])->run();

        $this->assertEquals(Status::failed(), $result->status);
        $this->assertSame('0/7', $result->shortSummary);
        $this->assertStringContainsString('7 migration(s) have not run', $result->notificationMessage);

        foreach (array_slice($names, 0, 5) as $name) {
            $this->assertStringContainsString($name, $result->notificationMessage);
        }

        $this->assertStringNotContainsString($names[5], $result->notificationMessage);
        $this->assertStringNotContainsString($names[6], $result->notificationMessage);
        $this->assertStringContainsString('(+2 more)', $result->notificationMessage);
    }

    public function test_listed_limits_the_names_in_the_message(): void
    {
        $this->assertSame(0, Artisan::call('migrate:install'));
        $names = $this->writeMigrations(7);

        $result = SchemaCompleteCheck::new()->paths([$this->dir])->listed(2)->run();

        $this->assertEquals(Status::failed(), $result->status);
        $this->assertStringContainsString($names[0], $result->notificationMessage);
        $this->assertStringContainsString($names[1], $result->notificationMessage);
        $this->assertStringNotContainsString($names[2], $result->notificationMessage);
        $this->assertStringContainsString('(+5 more)', $result->notificationMessage);
    }

    public function test_listed_rejects_a_negative_count(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SchemaCompleteCheck::new()->listed(-1);
    }

    public function test_fails_when_migrations_table_is_missing(): void
    {
        $this->writeMigrations(1);

        $result = SchemaCompleteCheck::new()->paths([$this->dir])->run();

        $this->assertEquals(Status::failed(), $result->status);
        $this->assertSame('no table', $result->shortSummary);
        $this->assertStringContainsString('migrations', $result->notificationMessage);
    }

    public function test_failure_message_does_not_leak_exception_text(): void
    {
        $this->mockMigrator(function (MockInterface $migrator): void {
            $migrator->shouldReceive('repositoryExists')->andReturn(true);
            $migrator->shouldReceive('paths')->andReturn([]);
            $migrator->shouldReceive('getMigrationFiles')->andReturn([]);
            $migrator->shouldReceive('getRepository')->andThrow(
                new RuntimeException('SQLSTATE[HY000] [2002] host=db-secret.internal user=root_user')
            );
        });

        $result = SchemaCompleteCheck::new()->run();

        $this->assertEquals(Status::failed(), $result->status);
        $this->assertSame('unknown', $result->shortSummary);
        $this->assertStringNotContainsString('db-secret.internal', $result->notificationMessage);
        $this->assertStringNotContainsString('root_user', $result->notificationMessage);
        $this->assertStringContainsString(RuntimeException::class, $result->notificationMessage);
    }

    public function test_default_application_migration_path_is_included(): void
    {
        $expected = database_path('migrations');

        $this->mockMigrator(function (MockInterface $migrator) use ($expected): void {
            $repository = Mockery::mock(MigrationRepositoryInterface::class);
            $repository->shouldReceive('getRan')->andReturn([]);

            $migrator->shouldReceive('repositoryExists')->andReturn(true);
            $migrator->shouldReceive('paths')->andReturn(['/package/migrations']);
            $migrator->shouldReceive('getMigrationFiles')
                ->once()
                ->withArgs(fn (mixed $paths): bool => is_array($paths)
                    && in_array($expected, $paths, true)
                    && in_array('/package/migrations', $paths, true)
                    && in_array('/extra/migrations', $paths, true))
                ->andReturn([]);
            $migrator->shouldReceive('getRepository')->andReturn($repository);
        });

        $result = SchemaCompleteCheck::new()->paths(['/extra/migrations'])->run();

        $this->assertEquals(Status::ok(), $result->status);
        $this->assertSame('0/0', $result->shortSummary);
    }

    /**
     * @param  callable(MockInterface): void  $expectations
     */
    private function mockMigrator(callable $expectations): void
    {
        $migrator = Mockery::mock(Migrator::class);
        $expectations($migrator);

        $this->app?->instance('migrator', $migrator);
    }

    /**
     * Writes real, no-op migrations so `migrate` can run them.
     *
     * @return list<string> the migration names, in run order
     */
    private function writeMigrations(int $count): array
    {
        $names = [];

        for ($i = 1; $i <= $count; $i++) {
            $name = sprintf('2999_01_01_%06d_obs_fixture_%d', $i, $i);
            file_put_contents(
                $this->dir.'/'.$name.'.php',
                "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\n\n"
                ."return new class extends Migration\n{\n    public function up(): void {}\n};\n"
            );
            $names[] = $name;
        }

        return $names;
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}
