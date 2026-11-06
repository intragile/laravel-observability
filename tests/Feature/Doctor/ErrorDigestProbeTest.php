<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Tests\Feature\Doctor;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use IntrAgile\Observability\Doctor\DoctorOptions;
use IntrAgile\Observability\Doctor\Probes\ErrorDigestProbe;
use IntrAgile\Observability\Readiness\ReadinessEntry;
use IntrAgile\Observability\Readiness\ReadinessStatus;
use IntrAgile\Observability\Tests\TestCase;

final class ErrorDigestProbeTest extends TestCase
{
    private const WEBHOOK = 'https://hooks.slack.test/services/T000/B000/XXXX';

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app->make('config')->set('observability.error_digest.enabled', true);
        $app->make('config')->set('observability.error_digest.webhook_url', self::WEBHOOK);
    }

    public function test_no_http_call_without_send_test(): void
    {
        Http::fake();

        $entries = $this->probe(sendTest: false);

        Http::assertNothingSent();
        $this->assertNotSame(ReadinessStatus::Failed, $entries[0]->status);
    }

    public function test_send_test_posts_exactly_one_message(): void
    {
        Http::fake([self::WEBHOOK => Http::response('ok')]);

        $entries = $this->probe(sendTest: true);

        Http::assertSentCount(1);
        Http::assertSent(static fn (Request $request): bool => $request->url() === self::WEBHOOK
            && $request['text'] === '[observability:doctor] test message');
        $this->assertSame(ReadinessStatus::Ok, $this->delivery($entries)->status);
    }

    public function test_rejected_test_message_fails(): void
    {
        Http::fake([self::WEBHOOK => Http::response('no_service', 404)]);

        $entries = $this->probe(sendTest: true);

        $this->assertSame(ReadinessStatus::Failed, $this->delivery($entries)->status);
        $this->assertStringContainsString('404', $this->delivery($entries)->message);
    }

    public function test_missing_webhook_warns_without_send_test(): void
    {
        config()->set('observability.error_digest.webhook_url', null);
        Http::fake();

        $entries = $this->probe(sendTest: false);

        Http::assertNothingSent();
        $this->assertSame(ReadinessStatus::Warning, $entries[0]->status);
    }

    public function test_disabled_digest_is_skipped(): void
    {
        config()->set('observability.error_digest.enabled', false);
        Http::fake();

        $entries = $this->probe(sendTest: true);

        Http::assertNothingSent();
        $this->assertSame(ReadinessStatus::Skipped, $entries[0]->status);
    }

    /**
     * @return list<ReadinessEntry>
     */
    private function probe(bool $sendTest): array
    {
        return app(ErrorDigestProbe::class)->run(new DoctorOptions(live: false, sendTest: $sendTest, timeout: 2.0));
    }

    /**
     * @param  list<ReadinessEntry>  $entries
     */
    private function delivery(array $entries): ReadinessEntry
    {
        foreach ($entries as $entry) {
            if (str_contains($entry->component, 'delivery')) {
                return $entry;
            }
        }

        $this->fail('No delivery entry.');
    }
}
