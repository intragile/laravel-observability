<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Tests\Feature\Doctor;

use Illuminate\Contracts\Foundation\Application;
use IntrAgile\Observability\Doctor\DoctorOptions;
use IntrAgile\Observability\Doctor\Probes\LoggingProbe;
use IntrAgile\Observability\Logging\LokiJsonFormatter;
use IntrAgile\Observability\Readiness\ReadinessEntry;
use IntrAgile\Observability\Readiness\ReadinessStatus;
use IntrAgile\Observability\Tests\TestCase;
use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;

final class LoggingProbeTest extends TestCase
{
    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $config = $app->make('config');
        $config->set('logging.default', 'stack');
        $config->set('logging.channels.stack', ['driver' => 'stack', 'channels' => ['json']]);
        $config->set('logging.channels.json', $this->channel(LokiJsonFormatter::class));
    }

    public function test_loki_formatter_is_ok(): void
    {
        $entries = $this->probe();

        $this->assertCount(1, $entries);
        $this->assertSame(ReadinessStatus::Ok, $entries[0]->status, $entries[0]->message);
        $this->assertStringContainsString('json', $entries[0]->component);
    }

    public function test_line_formatter_is_not_structured(): void
    {
        config()->set('logging.channels.json', $this->channel(LineFormatter::class));

        $entries = $this->probe();

        $this->assertSame(ReadinessStatus::Warning, $entries[0]->status);
        $this->assertStringContainsString('not structured', $entries[0]->message);
    }

    public function test_disabled_redaction_fails(): void
    {
        config()->set('observability.logging.redact_sql', false);

        $entries = $this->probe();

        $this->assertSame(ReadinessStatus::Failed, $entries[0]->status);
        $this->assertStringContainsString('SQL', $entries[0]->message);
    }

    public function test_json_formatter_without_service_and_env_fails(): void
    {
        config()->set('logging.channels.json', $this->channel(JsonFormatter::class));

        $entries = $this->probe();

        $this->assertSame(ReadinessStatus::Failed, $entries[0]->status);
        $this->assertStringContainsString('service', $entries[0]->message);
    }

    public function test_default_channel_without_stack_is_probed(): void
    {
        config()->set('logging.default', 'json');

        $entries = $this->probe();

        $this->assertCount(1, $entries);
        $this->assertSame(ReadinessStatus::Ok, $entries[0]->status, $entries[0]->message);
    }

    public function test_probe_is_static(): void
    {
        $this->assertFalse(app(LoggingProbe::class)->live());
    }

    /**
     * @return list<ReadinessEntry>
     */
    private function probe(): array
    {
        return app(LoggingProbe::class)->run(new DoctorOptions(live: false, sendTest: false, timeout: 5.0));
    }

    /**
     * @return array<string, mixed>
     */
    private function channel(string $formatter): array
    {
        return [
            'driver' => 'monolog',
            'handler' => StreamHandler::class,
            'with' => ['stream' => 'php://memory'],
            'formatter' => $formatter,
        ];
    }
}
