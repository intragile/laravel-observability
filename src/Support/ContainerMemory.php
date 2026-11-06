<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Support;

/**
 * Reads the container's own memory limit and current usage from the cgroup filesystem.
 *
 * Processes spawned from PHP (OCR engines, PDF converters and similar external tools) allocate
 * memory outside PHP's `memory_limit`. When a tight cgroup budget runs out, the result is not an
 * exception but the kernel OOM killer terminating a process (`exit 137`), often before the
 * command can record anything. This class lets a caller ask up front whether there is room left
 * and skip a round instead.
 *
 * Usage is the anonymous memory (`anon` on cgroup v2, `rss` on cgroup v1), not
 * `memory.current`. `memory.current` also includes the page cache (`file`), which the kernel
 * reclaims under pressure; counting it would make a container look full after merely reading a
 * large file. Anonymous memory is what actually leads to an OOM kill. Memory of a synchronous
 * child process in the same cgroup is included while it runs and released as soon as it exits.
 *
 * The reading is a point-in-time snapshot and gives no guarantee against work that starts right
 * after the check. A hard guarantee requires a larger container limit.
 *
 * Unknown means unconstrained: on a development machine, without a memory limit, or when the
 * cgroup files are unreadable, the methods return `null` and the caller should behave as if
 * there were no limit. An unreadable system file must never stop a feature.
 */
final class ContainerMemory
{
    /**
     * cgroup v1 reports "no limit" as a value close to PHP_INT_MAX; anything above this is
     * treated as unlimited.
     */
    private const V1_UNLIMITED_THRESHOLD = 1 << 50;

    public function __construct(private readonly string $root = '/sys/fs/cgroup') {}

    /**
     * The number of bytes that can still be allocated before reaching the limit.
     *
     * @return int|null null when the limit or the usage cannot be determined
     */
    public function availableBytes(): ?int
    {
        $limit = $this->limitBytes();
        $used = $this->usedBytes();

        if ($limit === null || $used === null) {
            return null;
        }

        return max(0, $limit - $used);
    }

    /**
     * The cgroup memory limit: `memory.max` on v2 (`max` means unlimited) or
     * `memory/memory.limit_in_bytes` on v1.
     *
     * @return int|null null when unlimited or unreadable
     */
    public function limitBytes(): ?int
    {
        $v2 = $this->read('memory.max');

        if ($v2 !== null) {
            return $v2 === 'max' ? null : $this->positive($this->toInt($v2));
        }

        $v1 = $this->read('memory/memory.limit_in_bytes');

        if ($v1 === null) {
            return null;
        }

        $bytes = $this->toInt($v1);

        return ($bytes === null || $bytes > self::V1_UNLIMITED_THRESHOLD) ? null : $this->positive($bytes);
    }

    /**
     * The anonymous memory currently in use, excluding the reclaimable page cache.
     *
     * @return int|null null when there is no limit or the usage is unreadable
     */
    public function usedBytes(): ?int
    {
        if ($this->limitBytes() === null) {
            return null;
        }

        return $this->anonymousBytes();
    }

    private function anonymousBytes(): ?int
    {
        // The same concept under different names: `anon` on v2, `rss` on v1.
        foreach (['memory.stat' => 'anon', 'memory/memory.stat' => 'rss'] as $file => $key) {
            $contents = $this->read($file);

            if ($contents === null) {
                continue;
            }

            if (preg_match('/^'.$key.'\s+(\d+)$/m', $contents, $matches) === 1) {
                return (int) $matches[1];
            }
        }

        return null;
    }

    private function read(string $relative): ?string
    {
        $path = $this->root.'/'.$relative;

        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        return $contents === false ? null : trim($contents);
    }

    private function toInt(string $value): ?int
    {
        return preg_match('/^\d+$/', $value) === 1 ? (int) $value : null;
    }

    private function positive(?int $value): ?int
    {
        return ($value === null || $value <= 0) ? null : $value;
    }
}
