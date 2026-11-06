<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Tests\Unit;

use FilesystemIterator;
use IntrAgile\Observability\Support\ContainerMemory;
use IntrAgile\Observability\Tests\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Reading the container memory budget from the cgroup filesystem.
 *
 * The most important assertion is that the page cache (`file`) is not counted as used memory:
 * it is reclaimable, and counting it would make a container look full after reading a single
 * large file.
 */
final class ContainerMemoryTest extends TestCase
{
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

    public function test_it_reports_the_free_bytes_of_a_cgroup_v2_container(): void
    {
        $this->write('memory.max', (string) (256 * 1024 * 1024));
        $this->write('memory.stat', "anon 150994944\nfile 20971520\nkernel 1048576\n");

        $this->assertSame(
            (256 * 1024 * 1024) - 150994944,
            (new ContainerMemory($this->root))->availableBytes()
        );
    }

    public function test_an_unlimited_container_reports_unknown_instead_of_zero(): void
    {
        $this->write('memory.max', 'max');
        $this->write('memory.stat', "anon 150994944\n");

        $this->assertNull(
            (new ContainerMemory($this->root))->availableBytes(),
            'An unlimited container has nothing to measure; callers must not see a limit.'
        );
    }

    public function test_the_page_cache_does_not_count_as_used_memory(): void
    {
        // 200 MB of page cache in a 256M container is reclaimable, not used memory.
        $this->write('memory.max', (string) (256 * 1024 * 1024));
        $this->write('memory.stat', "anon 52428800\nfile 209715200\n");

        $this->assertSame(
            (256 * 1024 * 1024) - 52428800,
            (new ContainerMemory($this->root))->availableBytes(),
            'The page cache was counted as used memory; a container would look permanently full '
            .'after reading a single large file.'
        );
    }

    public function test_a_missing_cgroup_is_unknown_never_zero(): void
    {
        $this->assertNull(
            (new ContainerMemory($this->root.'/missing'))->availableBytes(),
            'An unreadable system file must let the caller proceed, not stop it.'
        );
    }

    public function test_limit_and_used_bytes_are_read_from_cgroup_v2(): void
    {
        $this->write('memory.max', '268435456');
        $this->write('memory.stat', "anon 52428800\nfile 209715200\n");

        $memory = new ContainerMemory($this->root);

        $this->assertSame(268435456, $memory->limitBytes());
        $this->assertSame(52428800, $memory->usedBytes());
    }

    public function test_limit_and_used_bytes_are_read_from_cgroup_v1(): void
    {
        $this->write('memory/memory.limit_in_bytes', "536870912\n");
        $this->write('memory/memory.stat', "cache 104857600\nrss 134217728\nrss_huge 0\n");

        $memory = new ContainerMemory($this->root);

        $this->assertSame(536870912, $memory->limitBytes());
        $this->assertSame(134217728, $memory->usedBytes());
        $this->assertSame(536870912 - 134217728, $memory->availableBytes());
    }

    public function test_v2_max_reports_null_limit_and_used_bytes(): void
    {
        $this->write('memory.max', 'max');
        $this->write('memory.stat', "anon 52428800\n");

        $memory = new ContainerMemory($this->root);

        $this->assertNull($memory->limitBytes());
        $this->assertNull($memory->usedBytes());
    }

    public function test_v1_unlimited_sentinel_reports_null_limit_and_used_bytes(): void
    {
        $this->write('memory/memory.limit_in_bytes', '9223372036854771712');
        $this->write('memory/memory.stat', "rss 134217728\n");

        $memory = new ContainerMemory($this->root);

        $this->assertNull($memory->limitBytes());
        $this->assertNull($memory->usedBytes());
        $this->assertNull($memory->availableBytes());
    }

    public function test_missing_cgroup_reports_null_limit_and_used_bytes(): void
    {
        $memory = new ContainerMemory($this->root.'/missing');

        $this->assertNull($memory->limitBytes());
        $this->assertNull($memory->usedBytes());
    }

    public function test_non_numeric_limit_is_treated_as_unknown(): void
    {
        $this->write('memory.max', 'garbage');
        $this->write('memory.stat', "anon 52428800\n");

        $memory = new ContainerMemory($this->root);

        $this->assertNull($memory->limitBytes());
        $this->assertNull($memory->usedBytes());
        $this->assertNull($memory->availableBytes());
    }

    public function test_used_bytes_is_null_when_memory_stat_is_unreadable(): void
    {
        $this->write('memory.max', '268435456');

        $memory = new ContainerMemory($this->root);

        $this->assertSame(268435456, $memory->limitBytes());
        $this->assertNull($memory->usedBytes());
        $this->assertNull($memory->availableBytes());
    }

    private function write(string $name, string $contents): void
    {
        $path = $this->root.'/'.$name;
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true)) {
            throw new RuntimeException("Cannot create fixture directory {$directory}.");
        }

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
