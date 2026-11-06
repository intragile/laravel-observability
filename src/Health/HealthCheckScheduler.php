<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Health;

use Closure;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository;
use RuntimeException;
use Spatie\Health\Commands\RunHealthChecksCommand;

/**
 * Schedules spatie's health:check only once a warmup window has passed since the deploy.
 *
 * Right after a deploy the heartbeat cache namespace is empty, so the first ticks would report
 * scheduler and queue heartbeats as failing and send alerts for problems that do not exist.
 * Sleeping inside the tick is not an option because it would delay every other command on the
 * same tick, so the start of the window is persisted in a flag file instead and the command is
 * simply left off the schedule until the window has elapsed.
 *
 * The flag records a deploy id as well, because storage that lives on a persistent volume
 * survives the deploy and an old timestamp would otherwise skip the warmup entirely. When the
 * flag cannot be written the failure is reported and health checks are scheduled anyway: losing
 * monitoring silently is worse than a few early alerts.
 */
final class HealthCheckScheduler
{
    private const DEFAULT_FLAG_PATH = 'app/observability/health-warmup.json';

    private const DEFAULT_WARMUP_SECONDS = 300;

    private readonly Closure $clock;

    public function __construct(
        private readonly string $flagPath,
        private readonly int $warmupSeconds,
        private readonly ?string $deployId = null,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? time(...);
    }

    /**
     * @param  Closure(string): string  $storagePath
     */
    public static function fromConfig(Repository $config, Closure $storagePath): self
    {
        $flagPath = $config->get('observability.health.schedule.flag_path');

        if (! is_string($flagPath) || $flagPath === '') {
            $flagPath = $storagePath(self::DEFAULT_FLAG_PATH);
        }

        $warmup = $config->get('observability.health.schedule.warmup_seconds', self::DEFAULT_WARMUP_SECONDS);
        $warmup = is_numeric($warmup) ? max(0, (int) $warmup) : self::DEFAULT_WARMUP_SECONDS;

        $deployId = $config->get('observability.deploy_id');
        $deployId = is_string($deployId) && $deployId !== '' ? $deployId : null;

        return new self($flagPath, $warmup, $deployId);
    }

    /**
     * Seconds left before health:check is scheduled. A missing, corrupt or foreign flag means the
     * next tick starts a new window, so the full warmup is still ahead. Never writes the flag.
     */
    public function remainingWarmup(): int
    {
        $flag = $this->readFlag();

        if (! $this->flagIsCurrent($flag)) {
            return $this->warmupSeconds;
        }

        return max(0, $this->warmupSeconds - ($this->now() - $flag['deployed_at']));
    }

    /**
     * Seconds left of a warmup window that a scheduler tick has actually started for this deploy,
     * or null when no such window exists. A missing, corrupt or foreign flag only proves that no
     * tick has run since the deploy, not that a warmup is in progress. Never writes the flag.
     */
    public function activeWarmup(): ?int
    {
        $flag = $this->readFlag();

        if (! $this->flagIsCurrent($flag)) {
            return null;
        }

        return max(0, $this->warmupSeconds - ($this->now() - $flag['deployed_at']));
    }

    /**
     * Decides whether health:check belongs on this tick's schedule, starting the warmup window
     * when this is the first tick after a deploy.
     */
    public function shouldSchedule(): bool
    {
        $now = $this->now();
        $flag = $this->readFlag();

        if ($this->flagIsCurrent($flag)) {
            return $now - $flag['deployed_at'] >= $this->warmupSeconds;
        }

        try {
            $this->writeFlag($now);
        } catch (RuntimeException $e) {
            report($e);

            return true;
        }

        return $this->warmupSeconds <= 0;
    }

    public function register(Schedule $schedule, string $cron, bool $onOneServer): ?Event
    {
        if (! $this->shouldSchedule()) {
            return null;
        }

        $event = $schedule->command(RunHealthChecksCommand::class)->cron($cron);

        if ($onOneServer) {
            $event->onOneServer();
        }

        return $event;
    }

    /**
     * Reads the flag in either the current JSON format or the legacy bare-timestamp format,
     * returning null when it is missing or cannot be interpreted.
     *
     * @return array{deployed_at: int, deploy_id: ?string}|null
     */
    private function readFlag(): ?array
    {
        if (! is_file($this->flagPath)) {
            return null;
        }

        $raw = @file_get_contents($this->flagPath);

        if ($raw === false) {
            return null;
        }

        $trimmed = trim($raw);

        if ($trimmed !== '' && ctype_digit($trimmed)) {
            return ['deployed_at' => (int) $trimmed, 'deploy_id' => null];
        }

        $decoded = json_decode($trimmed, true);

        if (! is_array($decoded) || ! is_int($decoded['deployed_at'] ?? null)) {
            return null;
        }

        $deployId = $decoded['deploy_id'] ?? null;

        if ($deployId !== null && ! is_string($deployId)) {
            return null;
        }

        return ['deployed_at' => $decoded['deployed_at'], 'deploy_id' => $deployId];
    }

    /**
     * @param  array{deployed_at: int, deploy_id: ?string}|null  $flag
     *
     * @phpstan-assert-if-true array{deployed_at: int, deploy_id: ?string} $flag
     */
    private function flagIsCurrent(?array $flag): bool
    {
        return $flag !== null && ($this->deployId === null || $this->deployId === $flag['deploy_id']);
    }

    /**
     * Writes through a temporary file and a rename so that a concurrent reader on another
     * container never sees a half-written flag. Warnings are suppressed on purpose because every
     * failure is turned into an exception the caller reports.
     */
    private function writeFlag(int $now): void
    {
        $directory = dirname($this->flagPath);

        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to write the health check warmup flag at [{$this->flagPath}].");
        }

        $payload = json_encode(['deployed_at' => $now, 'deploy_id' => $this->deployId], JSON_THROW_ON_ERROR);
        $temporary = $this->flagPath.'.'.getmypid().'.tmp';

        if (@file_put_contents($temporary, $payload) === false || ! @rename($temporary, $this->flagPath)) {
            if (is_file($temporary)) {
                @unlink($temporary);
            }

            throw new RuntimeException("Unable to write the health check warmup flag at [{$this->flagPath}].");
        }
    }

    private function now(): int
    {
        $now = ($this->clock)();

        return is_int($now) ? $now : time();
    }
}
