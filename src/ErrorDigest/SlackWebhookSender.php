<?php

declare(strict_types=1);

namespace IntrAgile\Observability\ErrorDigest;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Posts a Block Kit payload to a Slack incoming webhook.
 */
final class SlackWebhookSender
{
    private const TIMEOUT_SECONDS = 10;

    /**
     * Returns true only when Slack answered with a 2xx status. Transport
     * errors are reported as false instead of being thrown.
     *
     * @param  array<string, mixed>  $payload
     */
    public function send(string $url, array $payload): bool
    {
        try {
            return Http::timeout(self::TIMEOUT_SECONDS)
                ->asJson()
                ->post($url, $payload)
                ->successful();
        } catch (Throwable) {
            return false;
        }
    }
}
