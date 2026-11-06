<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Tests\Unit\Checks;

use FilesystemIterator;
use IntrAgile\Observability\Checks\ContainerMemoryCheck;
use IntrAgile\Observability\Tests\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class ContainerMemoryCheckTest extends TestCase
{
    private const LIMIT = 1000000000;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/obs-'.bin2hex(random_bytes(6));

        if (! mkdir($this->root, 0755, true)) {
            throw new RuntimeException("Cannot create fixture directory {$this->root}.");
        }
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->root);

        parent::tearDown();
    }

    public function test_usage_below_warning_threshold_is_ok(): void
    {
        $this->usage(500000000);

        $result = ContainerMemoryCheck::new()->root($this->root)->run();

        $this->assertSame('ok', $result->status->value);
    }

    public function test_usage_above_warning_threshold_is_warning(): void
    {
        $this->usage(920000000);

        $result = ContainerMemoryCheck::new()->root($this->root)->run();

        $this->assertSame('warning', $result->status->value);
        $this->assertSame('92.0%', $result->getShortSummary());
    }

    public function test_usage_above_failure_threshold_is_failed(): void
    {
        $this->usage(970000000);

        $result = ContainerMemoryCheck::new()->root($this->root)->run();

        $this->assertSame('failed', $result->status->value);
        $this->assertStringContainsString('97', $result->getNotificationMessage());
    }

    public function test_no_memory_limit_is_ok_with_summary(): void
    {
        $this->write('memory.max', 'max');
        $this->write('memory.stat', "anon 500000000\n");

        $result = ContainerMemoryCheck::new()->root($this->root)->run();

        $this->assertSame('ok', $result->status->value);
        $this->assertSame('No cgroup memory limit', $result->getShortSummary());
        $this->assertSame(['limit_bytes' => null, 'used_bytes' => null, 'used_percent' => null], $result->meta);
    }

    public function test_missing_cgroup_is_ok_with_summary(): void
    {
        $result = ContainerMemoryCheck::new()->root($this->root.'/missing')->run();

        $this->assertSame('ok', $result->status->value);
        $this->assertSame('No cgroup memory limit', $result->getShortSummary());
    }

    public function test_meta_contains_limit_used_and_percent(): void
    {
        $this->usage(500000000);

        $result = ContainerMemoryCheck::new()->root($this->root)->run();

        $this->assertSame(
            ['limit_bytes' => 1000000000, 'used_bytes' => 500000000, 'used_percent' => 50.0],
            $result->meta
        );
        $this->assertSame('50.0%', $result->getShortSummary());
    }

    public function test_thresholds_are_configurable(): void
    {
        $this->usage(500000000);

        $warning = ContainerMemoryCheck::new()->root($this->root)->warnWhenUsedPercentAbove(40)->run();
        $failed = ContainerMemoryCheck::new()
            ->root($this->root)
            ->warnWhenUsedPercentAbove(40)
            ->failWhenUsedPercentAbove(45)
            ->run();

        $this->assertSame('warning', $warning->status->value);
        $this->assertSame('failed', $failed->status->value);
    }

    public function test_setters_return_the_same_instance(): void
    {
        $check = ContainerMemoryCheck::new();

        $this->assertSame($check, $check->root($this->root));
        $this->assertSame($check, $check->warnWhenUsedPercentAbove(80));
        $this->assertSame($check, $check->failWhenUsedPercentAbove(90));
    }

    private function usage(int $anonBytes): void
    {
        $this->write('memory.max', (string) self::LIMIT);
        $this->write('memory.stat', "anon {$anonBytes}\nfile 300000000\n");
    }

    private function write(string $name, string $contents): void
    {
        $path = $this->root.'/'.$name;

        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException("Cannot write fixture file {$path}.");
        }
    }

    private static function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if (! $item instanceof SplFileInfo) {
                continue;
            }

            $item->isDir() && ! $item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($directory);
    }
}
