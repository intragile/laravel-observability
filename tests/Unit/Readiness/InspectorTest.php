<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Tests\Unit\Readiness;

use Closure;
use FilesystemIterator;
use Illuminate\Contracts\Config\Repository;
use IntrAgile\Observability\Checks\BackupFreshnessCheck;
use IntrAgile\Observability\Checks\BroadcastCheck;
use IntrAgile\Observability\Checks\ContainerMemoryCheck;
use IntrAgile\Observability\Checks\FailedJobsCheck;
use IntrAgile\Observability\Checks\MailRelayCheck;
use IntrAgile\Observability\Checks\RequestTimingCheck;
use IntrAgile\Observability\Checks\SchemaCompleteCheck;
use IntrAgile\Observability\Logging\LokiJsonFormatter;
use IntrAgile\Observability\Readiness\Inspector;
use IntrAgile\Observability\Readiness\ReadinessEntry;
use IntrAgile\Observability\Readiness\ReadinessStatus;
use IntrAgile\Observability\Tests\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Spatie\Health\Checks\Check;
use SplFileInfo;

final class InspectorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        // An empty directory stands in for a host without a cgroup memory limit.
        $this->root = sys_get_temp_dir().'/obs-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0755, true);

        $this->config()->set('observability.health.checks.container_memory.root', $this->root);
        $this->config()->set('observability.prometheus.basic_auth.password', 's3cret');
        $this->config()->set('cache.default', 'file');
        $this->config()->set('logging.channels.json', ['driver' => 'monolog', 'formatter' => LokiJsonFormatter::class]);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);

        parent::tearDown();
    }

    public function test_missing_optional_packages_are_skipped(): void
    {
        $inspector = $this->inspector(classExists: static fn (string $class): bool => false);

        $entries = $inspector->inspect();
        $checks = $inspector->checks();

        $this->assertSame(ReadinessStatus::Skipped, $this->entry($entries, 'BroadcastCheck')->status);
        $this->assertStringContainsString('laravel/reverb', $this->entry($entries, 'BroadcastCheck')->message);
        $this->assertStringContainsString('phrity/websocket', $this->entry($entries, 'BroadcastCheck')->message);

        $this->assertSame(ReadinessStatus::Skipped, $this->entry($entries, 'BackupFreshnessCheck')->status);
        $this->assertStringContainsString('spatie/laravel-backup', $this->entry($entries, 'BackupFreshnessCheck')->message);

        $this->assertSame(ReadinessStatus::Skipped, $this->entry($entries, 'Prometheus')->status);
        $this->assertStringContainsString('spatie/laravel-prometheus', $this->entry($entries, 'Prometheus')->message);

        $this->assertSame(ReadinessStatus::Skipped, $this->entry($entries, 'Doctor integration')->status);
        $this->assertStringContainsString('laravel/doctor', $this->entry($entries, 'Doctor integration')->message);

        $classes = $this->classesOf($checks);
        $this->assertNotContains(BroadcastCheck::class, $classes);
        $this->assertNotContains(BackupFreshnessCheck::class, $classes);
        $this->assertContains(SchemaCompleteCheck::class, $classes);
    }

    public function test_broadcast_check_needs_both_reverb_and_websocket_client(): void
    {
        $inspector = $this->inspector(classExists: static fn (string $class): bool => $class !== Inspector::WEBSOCKET_CLIENT_CLASS);

        $entry = $this->entry($inspector->inspect(), 'BroadcastCheck');

        $this->assertSame(ReadinessStatus::Skipped, $entry->status);
        $this->assertStringContainsString('phrity/websocket', $entry->message);
        $this->assertNotContains(BroadcastCheck::class, $this->classesOf($inspector->checks()));

        $inspector = $this->inspector(classExists: static fn (string $class): bool => $class !== Inspector::REVERB_CLASS);

        $this->assertSame(ReadinessStatus::Skipped, $this->entry($inspector->inspect(), 'BroadcastCheck')->status);
        $this->assertNotContains(BroadcastCheck::class, $this->classesOf($inspector->checks()));
    }

    public function test_all_checks_are_built_when_every_dependency_is_present(): void
    {
        $this->writeCgroupLimit();

        $inspector = $this->inspector();

        $this->assertSame([
            SchemaCompleteCheck::class,
            FailedJobsCheck::class,
            MailRelayCheck::class,
            RequestTimingCheck::class,
            BackupFreshnessCheck::class,
            BroadcastCheck::class,
            ContainerMemoryCheck::class,
        ], $this->classesOf($inspector->checks()));

        foreach (['BroadcastCheck', 'BackupFreshnessCheck', 'ContainerMemoryCheck', 'Prometheus', 'Doctor integration'] as $component) {
            $this->assertSame(ReadinessStatus::Ok, $this->entry($inspector->inspect(), $component)->status, $component);
        }
    }

    public function test_disabled_checks_are_skipped_and_not_built(): void
    {
        $this->config()->set('observability.health.checks.failed_jobs.enabled', false);

        $inspector = $this->inspector();

        $this->assertSame(ReadinessStatus::Skipped, $this->entry($inspector->inspect(), 'FailedJobsCheck')->status);
        $this->assertNotContains(FailedJobsCheck::class, $this->classesOf($inspector->checks()));
    }

    public function test_container_memory_check_is_skipped_outside_a_container(): void
    {
        $inspector = $this->inspector();

        $entry = $this->entry($inspector->inspect(), 'ContainerMemoryCheck');

        $this->assertSame(ReadinessStatus::Skipped, $entry->status);
        $this->assertStringContainsString('cgroup', $entry->message);
        $this->assertNotContains(ContainerMemoryCheck::class, $this->classesOf($inspector->checks()));
    }

    public function test_basic_auth_without_password_warns(): void
    {
        $this->config()->set('observability.prometheus.basic_auth.password', null);
        $this->config()->set('observability.prometheus.basic_auth.password_file', $this->root.'/missing');

        $entry = $this->entry($this->inspector()->inspect(), 'Prometheus');

        $this->assertSame(ReadinessStatus::Warning, $entry->status);
        $this->assertStringContainsString('every scrape will be rejected', $entry->message);
    }

    public function test_basic_auth_password_file_counts_as_password(): void
    {
        file_put_contents($this->root.'/password', "s3cret\n");
        $this->config()->set('observability.prometheus.basic_auth.password', null);
        $this->config()->set('observability.prometheus.basic_auth.password_file', $this->root.'/password');

        $this->assertSame(ReadinessStatus::Ok, $this->entry($this->inspector()->inspect(), 'Prometheus')->status);
    }

    public function test_array_heartbeat_store_warns(): void
    {
        $this->config()->set('observability.health.checks.backup_freshness.store', 'array');

        $entry = $this->entry($this->inspector()->inspect(), 'BackupFreshnessCheck');

        $this->assertSame(ReadinessStatus::Warning, $entry->status);
        $this->assertStringContainsString('array', $entry->message);
    }

    public function test_default_store_with_array_driver_warns(): void
    {
        $this->config()->set('observability.health.checks.backup_freshness.store', null);
        $this->config()->set('cache.default', 'array');

        $this->assertSame(ReadinessStatus::Warning, $this->entry($this->inspector()->inspect(), 'BackupFreshnessCheck')->status);

        $this->config()->set('cache.default', 'file');

        $this->assertSame(ReadinessStatus::Ok, $this->entry($this->inspector()->inspect(), 'BackupFreshnessCheck')->status);
    }

    public function test_digest_without_webhook_warns(): void
    {
        $this->config()->set('observability.error_digest.enabled', true);
        $this->config()->set('observability.error_digest.webhook_url', null);

        $entry = $this->entry($this->inspector(tableExists: static fn (): bool => true)->inspect(), 'Error digest');

        $this->assertSame(ReadinessStatus::Warning, $entry->status);
        $this->assertStringContainsString('webhook', $entry->message);
    }

    public function test_digest_without_table_warns(): void
    {
        $this->config()->set('observability.error_digest.enabled', true);
        $this->config()->set('observability.error_digest.webhook_url', 'https://hooks.slack.test/services/X');

        $entry = $this->entry($this->inspector(tableExists: static fn (): bool => false)->inspect(), 'Error digest');

        $this->assertSame(ReadinessStatus::Warning, $entry->status);
        $this->assertStringContainsString('observability_error_reports', $entry->message);

        $entry = $this->entry($this->inspector(tableExists: static fn (): bool => true)->inspect(), 'Error digest');

        $this->assertSame(ReadinessStatus::Ok, $entry->status);
    }

    public function test_disabled_digest_is_skipped(): void
    {
        $this->config()->set('observability.error_digest.enabled', false);

        $this->assertSame(ReadinessStatus::Skipped, $this->entry($this->inspector()->inspect(), 'Error digest')->status);
    }

    public function test_missing_loki_formatter_warns(): void
    {
        $this->config()->set('logging.channels.json', ['driver' => 'monolog']);

        $entry = $this->entry($this->inspector()->inspect(), 'Structured logging');

        $this->assertSame(ReadinessStatus::Warning, $entry->status);
        $this->assertStringContainsString(LokiJsonFormatter::class, $entry->message);
    }

    public function test_loki_formatter_on_any_channel_is_ok(): void
    {
        $entry = $this->entry($this->inspector()->inspect(), 'Structured logging');

        $this->assertSame(ReadinessStatus::Ok, $entry->status);
        $this->assertStringContainsString('json', $entry->message);
    }

    public function test_checks_carry_configured_values(): void
    {
        $this->config()->set('observability.health.checks.request_timing.routes', ['reports.export' => ['warn' => 2000, 'fail' => 5000]]);

        $checks = $this->inspector()->checks();
        $names = array_map(static fn (Check $check): string => $check->getName(), $checks);

        $this->assertSame(array_values(array_unique($names)), $names);
        $this->assertContains(RequestTimingCheck::class, $this->classesOf($checks));
    }

    public function test_never_produces_a_failed_entry(): void
    {
        $this->config()->set('observability.error_digest.enabled', true);
        $this->config()->set('observability.prometheus.basic_auth.password', null);

        $statuses = array_map(
            static fn (ReadinessEntry $entry): ReadinessStatus => $entry->status,
            $this->inspector(tableExists: static fn (): bool => false)->inspect(),
        );

        $this->assertNotContains(ReadinessStatus::Failed, $statuses);
    }

    /**
     * @param  (Closure(string): bool)|null  $classExists
     * @param  (Closure(): bool)|null  $tableExists
     */
    private function inspector(?Closure $classExists = null, ?Closure $tableExists = null): Inspector
    {
        return new Inspector(
            $this->config(),
            $classExists ?? static fn (string $class): bool => true,
            $tableExists ?? static fn (): bool => true,
        );
    }

    private function config(): Repository
    {
        $config = $this->app?->make('config');
        $this->assertInstanceOf(Repository::class, $config);

        return $config;
    }

    /**
     * @param  list<ReadinessEntry>  $entries
     */
    private function entry(array $entries, string $component): ReadinessEntry
    {
        foreach ($entries as $entry) {
            if ($entry->component === $component) {
                return $entry;
            }
        }

        $this->fail("No readiness entry for {$component}.");
    }

    /**
     * @param  list<Check>  $checks
     * @return list<class-string<Check>>
     */
    private function classesOf(array $checks): array
    {
        return array_map(static fn (Check $check): string => $check::class, $checks);
    }

    private function writeCgroupLimit(): void
    {
        file_put_contents($this->root.'/memory.max', "1073741824\n");
        file_put_contents($this->root.'/memory.stat', "anon 268435456\nfile 1024\n");
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            assert($item instanceof SplFileInfo);
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($directory);
    }
}
