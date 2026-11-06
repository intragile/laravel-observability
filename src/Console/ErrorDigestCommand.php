<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use IntrAgile\Observability\ErrorDigest\DigestBuilder;
use IntrAgile\Observability\ErrorDigest\ErrorReport;
use IntrAgile\Observability\ErrorDigest\SlackWebhookSender;

/**
 * Sends unsent error reports to Slack as one grouped digest.
 *
 * Delivery semantics: the IDs of at most batch_size unsent rows are claimed,
 * and exactly those rows are marked as sent, only after Slack accepted the
 * message. A failed delivery leaves every row unsent for the next run.
 */
final class ErrorDigestCommand extends Command
{
    public const LOCK_NAME = 'observability:error-digest';

    private const LOCK_SECONDS = 300;

    /** @var string */
    protected $signature = 'observability:error-digest';

    /** @var string */
    protected $description = 'Send unsent error reports to Slack as a grouped digest';

    public function handle(DigestBuilder $builder, SlackWebhookSender $sender): int
    {
        $lock = $this->acquireLock();

        if ($lock === false) {
            $this->info('Another error digest run is in progress; skipping.');

            return self::SUCCESS;
        }

        try {
            $status = $this->deliver($builder, $sender);
            $this->prune();

            return $status;
        } finally {
            $lock?->release();
        }
    }

    private function deliver(DigestBuilder $builder, SlackWebhookSender $sender): int
    {
        $webhook = $this->stringConfig('webhook_url', '');

        if ($webhook === '') {
            $pending = ErrorReport::query()->whereNull('sent_at')->count();
            $this->warn('No Slack webhook configured (observability.error_digest.webhook_url); '.$pending.' report(s) left unsent.');

            return self::SUCCESS;
        }

        /** @var list<int> $ids */
        $ids = ErrorReport::query()
            ->whereNull('sent_at')
            ->orderBy('id')
            ->limit(max(1, $this->intConfig('batch_size', 500)))
            ->pluck('id')
            ->map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)
            ->all();

        if ($ids === []) {
            $this->info('No unsent error reports.');

            return self::SUCCESS;
        }

        $rows = ErrorReport::query()->whereKey($ids)->get();
        $groups = $builder->build($rows, $this->intConfig('max_groups', 10));
        $hidden = $builder->groupCount($rows) - count($groups);
        $payload = $builder->payload($groups, $this->instance(), $this->links(), $hidden);

        if (! $sender->send($webhook, $payload)) {
            $this->error('Slack delivery failed; '.count($ids).' report(s) left unsent for the next run.');

            return self::FAILURE;
        }

        ErrorReport::query()
            ->whereKey($ids)
            ->whereNull('sent_at')
            ->update(['sent_at' => Carbon::now()]);

        $this->info('Sent '.count($ids).' report(s) in '.count($groups).' group(s).');

        return self::SUCCESS;
    }

    private function prune(): void
    {
        ErrorReport::query()
            ->whereNotNull('sent_at')
            ->where('created_at', '<', Carbon::now()->subDays(max(1, $this->intConfig('retention_days', 30))))
            ->delete();
    }

    /**
     * Returns the held lock, null when the cache store cannot lock, or false
     * when another run holds the lock.
     */
    private function acquireLock(): Lock|false|null
    {
        if (! Cache::getStore() instanceof LockProvider) {
            return null;
        }

        $lock = Cache::lock(self::LOCK_NAME, self::LOCK_SECONDS);

        return $lock->get() ? $lock : false;
    }

    private function instance(): string
    {
        $environment = config('app.env');
        $prefix = is_string($environment) && $environment !== 'production' ? '['.$environment.'] ' : '';

        return $prefix.$this->stringConfig('instance', 'laravel');
    }

    /**
     * @return array<array-key, mixed>
     */
    private function links(): array
    {
        $links = config('observability.error_digest.links');

        return is_array($links) ? $links : [];
    }

    private function stringConfig(string $key, string $default): string
    {
        $value = config('observability.error_digest.'.$key);

        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }

    private function intConfig(string $key, int $default): int
    {
        $value = config('observability.error_digest.'.$key);

        return is_numeric($value) ? (int) $value : $default;
    }
}
