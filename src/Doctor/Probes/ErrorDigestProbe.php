<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Doctor\Probes;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use IntrAgile\Observability\Doctor\DoctorOptions;
use IntrAgile\Observability\Doctor\Probe;
use IntrAgile\Observability\ErrorDigest\ErrorReport;
use IntrAgile\Observability\Readiness\ReadinessEntry;
use IntrAgile\Observability\Readiness\ReadinessStatus;
use Throwable;

/**
 * Checks that recorded errors can reach Slack.
 *
 * By default only the configuration is checked: a webhook URL and the report table. With
 * --send-test a real message is posted, which is the only way to prove the webhook is still valid,
 * because Slack revokes webhooks silently when the app or channel is removed. That message is an
 * effect outside the process, so it needs the explicit flag rather than --live.
 */
final class ErrorDigestProbe implements Probe
{
    public const TEST_MESSAGE = '[observability:doctor] test message';

    /** @var Closure(): bool */
    private readonly Closure $tableExists;

    /**
     * @param  (Closure(): bool)|null  $tableExists  whether the report table exists; may throw when the
     *                                               database is unreachable
     */
    public function __construct(
        private readonly Repository $config,
        ?Closure $tableExists = null,
    ) {
        $this->tableExists = $tableExists ?? static function (): bool {
            $model = new ErrorReport;

            return $model->getConnection()->getSchemaBuilder()->hasTable($model->getTable());
        };
    }

    public function name(): string
    {
        return 'Error digest probe';
    }

    public function live(): bool
    {
        return false;
    }

    public function run(DoctorOptions $options): array
    {
        if (! filter_var($this->config->get('observability.error_digest.enabled'), FILTER_VALIDATE_BOOLEAN)) {
            return [new ReadinessEntry('Error digest', ReadinessStatus::Skipped, 'Disabled by observability.error_digest.enabled.')];
        }

        $webhook = $this->config->get('observability.error_digest.webhook_url');
        $webhook = is_string($webhook) && trim($webhook) !== '' ? trim($webhook) : null;

        $entries = [$this->configuration($webhook, $options->sendTest)];

        if ($options->sendTest) {
            $entries[] = $this->deliver($webhook, $options->timeout);
        }

        return $entries;
    }

    private function configuration(?string $webhook, bool $sendTest): ReadinessEntry
    {
        $problems = [];

        if ($webhook === null) {
            $problems[] = 'no Slack webhook is configured (OBSERVABILITY_SLACK_WEBHOOK)';
        }

        $table = (new ErrorReport)->getTable();

        try {
            if (! ($this->tableExists)()) {
                $problems[] = "the {$table} table does not exist";
            }
        } catch (Throwable $exception) {
            $problems[] = "the {$table} table could not be checked (".$exception::class.')';
        }

        if ($problems !== []) {
            return new ReadinessEntry('Error digest', ReadinessStatus::Warning, ucfirst(implode('; ', $problems)).'.');
        }

        return new ReadinessEntry('Error digest', ReadinessStatus::Ok, 'A webhook is configured and the report table exists.'
            .($sendTest ? '' : ' Run with --send-test to prove Slack accepts messages.'));
    }

    /**
     * Only the exception class and the HTTP status are reported: the webhook URL is a secret and
     * transport error messages usually contain it.
     */
    private function deliver(?string $webhook, float $timeout): ReadinessEntry
    {
        $component = 'Error digest delivery';

        if ($webhook === null) {
            return new ReadinessEntry($component, ReadinessStatus::Failed, 'No webhook is configured to send the test message to.');
        }

        try {
            $response = Http::withOptions(['timeout' => $timeout, 'connect_timeout' => $timeout])
                ->asJson()
                ->post($webhook, ['text' => self::TEST_MESSAGE]);
        } catch (Throwable $exception) {
            return new ReadinessEntry($component, ReadinessStatus::Failed, 'The test message could not be sent ('.$exception::class.').');
        }

        if (! $response->successful()) {
            $body = Str::limit(trim($response->body()), 100);

            return new ReadinessEntry($component, ReadinessStatus::Failed, "Slack rejected the test message with HTTP {$response->status()}"
                .($body !== '' ? " ({$body})" : '').'.');
        }

        return new ReadinessEntry($component, ReadinessStatus::Ok, "Slack accepted the test message (HTTP {$response->status()}).");
    }
}
