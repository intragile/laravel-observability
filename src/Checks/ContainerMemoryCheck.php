<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Checks;

use IntrAgile\Observability\Support\ContainerMemory;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Reports the container's anonymous memory usage as a percentage of its cgroup limit.
 *
 * A container without a memory limit, or one whose cgroup files are unreadable, is reported as
 * healthy: there is no budget to exhaust.
 */
final class ContainerMemoryCheck extends Check
{
    protected string $cgroupRoot = '/sys/fs/cgroup';

    protected float $warnPercent = 90.0;

    protected float $failPercent = 95.0;

    public function root(string $root): static
    {
        $this->cgroupRoot = $root;

        return $this;
    }

    public function warnWhenUsedPercentAbove(float $percent): static
    {
        $this->warnPercent = $percent;

        return $this;
    }

    public function failWhenUsedPercentAbove(float $percent): static
    {
        $this->failPercent = $percent;

        return $this;
    }

    public function run(): Result
    {
        $memory = new ContainerMemory($this->cgroupRoot);
        $limit = $memory->limitBytes();
        $used = $memory->usedBytes();

        if ($limit === null || $used === null) {
            return Result::make()
                ->meta(['limit_bytes' => null, 'used_bytes' => null, 'used_percent' => null])
                ->shortSummary('No cgroup memory limit')
                ->ok();
        }

        $percent = round($used / $limit * 100, 2);
        $formatted = number_format($percent, 1);

        $result = Result::make()
            ->meta(['limit_bytes' => $limit, 'used_bytes' => $used, 'used_percent' => $percent])
            ->shortSummary($formatted.'%');

        $message = "Container memory usage is at {$formatted}% of the cgroup limit.";

        if ($percent > $this->failPercent) {
            return $result->failed($message);
        }

        if ($percent > $this->warnPercent) {
            return $result->warning($message);
        }

        return $result->ok();
    }
}
