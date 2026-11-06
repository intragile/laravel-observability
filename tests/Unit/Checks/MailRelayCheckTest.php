<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Tests\Unit\Checks;

use IntrAgile\Observability\Checks\MailRelayCheck;
use IntrAgile\Observability\Tests\TestCase;

final class MailRelayCheckTest extends TestCase
{
    /**
     * Each argument is written as given, including an explicit null: the null mailer and the null
     * host are cases under test, so they must reach the check instead of falling back to a default.
     */
    private function configureMail(?string $mailer, ?string $host, int|string|null $port, string $environment): void
    {
        config([
            'mail.default' => $mailer,
            'mail.mailers.smtp.host' => $host,
            'mail.mailers.smtp.port' => $port,
        ]);

        $this->app?->detectEnvironment(fn (): string => $environment);
    }

    private function statusOf(MailRelayCheck $check): int|string
    {
        return $check->run()->status->value;
    }

    public function test_silent_mailers_fail_in_production(): void
    {
        foreach (['log', 'array', 'null', '', null] as $mailer) {
            $this->configureMail($mailer, 'smtp.example.com', 587, 'production');

            $this->assertSame(
                'failed',
                $this->statusOf(new MailRelayCheck),
                'a mailer of '.var_export($mailer, true).' delivers to nobody',
            );
        }
    }

    public function test_mailpit_host_fails(): void
    {
        $this->configureMail('smtp', 'mailpit', 587, 'production');

        $result = (new MailRelayCheck)->run();

        $this->assertSame('failed', $result->status->value);
        $this->assertSame('catcher', $result->shortSummary);
        $this->assertStringContainsString('mailpit', $result->notificationMessage);
    }

    public function test_catcher_label_inside_hostname_fails(): void
    {
        $this->configureMail('smtp', 'smtp.mailpit.local', 587, 'production');

        $this->assertSame('failed', $this->statusOf(new MailRelayCheck));
    }

    public function test_catcher_port_alone_fails(): void
    {
        $this->configureMail('smtp', 'smtp.internal.example', 1025, 'production');

        $result = (new MailRelayCheck)->run();

        $this->assertSame('failed', $result->status->value);
        $this->assertStringContainsString('1025', $result->notificationMessage);
    }

    public function test_loopback_host_fails(): void
    {
        foreach (['127.0.0.1', 'localhost', '::1'] as $host) {
            $this->configureMail('smtp', $host, 587, 'production');

            $this->assertSame('failed', $this->statusOf(new MailRelayCheck), $host.' is the machine itself');
        }
    }

    public function test_empty_host_fails(): void
    {
        foreach (['', '   ', 'null', null] as $host) {
            $this->configureMail('smtp', $host, 587, 'production');

            $this->assertSame(
                'failed',
                $this->statusOf(new MailRelayCheck),
                'a host of '.var_export($host, true).' is no relay',
            );
        }
    }

    public function test_real_relay_passes(): void
    {
        $this->configureMail('smtp', 'smtp.example.com', 587, 'production');

        $check = new MailRelayCheck;
        $result = $check->run();

        $this->assertSame('ok', $result->status->value);
        $this->assertSame('relay', $result->shortSummary);
        $this->assertNull($check->whyNothingWouldArrive());
        $this->assertFalse($result->meta['held']);
    }

    public function test_does_not_cry_wolf(): void
    {
        foreach (['mail.example.com', 'mailhogwarts.example'] as $host) {
            $this->configureMail('smtp', $host, 587, 'production');

            $this->assertSame('ok', $this->statusOf(new MailRelayCheck), $host.' is a relay');
        }

        $this->configureMail('ses', 'mailpit', 1025, 'production');

        $this->assertSame('ok', $this->statusOf(new MailRelayCheck), 'an unknown transport is assumed to deliver');
    }

    public function test_log_mailer_is_ok_outside_monitored_environments(): void
    {
        $this->configureMail('log', 'smtp.example.com', 587, 'local');

        $check = new MailRelayCheck;

        $this->assertSame('ok', $this->statusOf($check));
        $this->assertNotNull($check->whyNothingWouldArrive());
    }

    public function test_hold_config_key_marks_result_as_held(): void
    {
        config(['app.mail_hold' => true]);
        $this->configureMail('log', 'smtp.example.com', 587, 'production');

        $result = (new MailRelayCheck)->holdConfigKey('app.mail_hold')->run();

        $this->assertSame('ok', $result->status->value);
        $this->assertSame('held', $result->shortSummary);
        $this->assertTrue($result->meta['held']);
    }

    public function test_hold_with_reason_string_is_reported(): void
    {
        config(['app.mail_hold' => 'Mail stays off until the launch.']);
        $this->configureMail('smtp', 'mailpit', 1025, 'production');

        $result = (new MailRelayCheck)->holdConfigKey('app.mail_hold')->run();

        $this->assertSame('ok', $result->status->value);
        $this->assertTrue($result->meta['held']);
        $this->assertStringContainsString('Mail stays off until the launch.', $result->notificationMessage);
    }

    public function test_empty_hold_does_not_silence_the_check(): void
    {
        foreach ([false, '', '   ', null, 0, '0', 'false', 'no', 'off', ' OFF '] as $hold) {
            config(['app.mail_hold' => $hold]);
            $this->configureMail('smtp', 'mailpit', 1025, 'production');

            $this->assertSame(
                'failed',
                $this->statusOf((new MailRelayCheck)->holdConfigKey('app.mail_hold')),
                'a hold of '.var_export($hold, true).' says nothing and must not silence the check',
            );
        }
    }

    public function test_truthy_hold_string_holds_without_being_quoted_as_reason(): void
    {
        foreach (['1', 'true', 'yes', 'on'] as $hold) {
            config(['app.mail_hold' => $hold]);
            $this->configureMail('smtp', 'mailpit', 1025, 'production');

            $result = (new MailRelayCheck)->holdConfigKey('app.mail_hold')->run();

            $this->assertSame('ok', $result->status->value, 'a hold of '.var_export($hold, true).' switches the hold on');
            $this->assertTrue($result->meta['held']);
            $this->assertStringContainsString('mail catcher', $result->notificationMessage);
        }
    }

    public function test_mailtrap_production_relays_are_not_catchers(): void
    {
        foreach (['live.smtp.mailtrap.io', 'bulk.smtp.mailtrap.io'] as $host) {
            $this->configureMail('smtp', $host, 587, 'production');

            $this->assertSame('ok', $this->statusOf(new MailRelayCheck), $host.' is a production relay');
        }

        $this->configureMail('smtp', 'sandbox.smtp.mailtrap.io', 2525, 'production');

        $this->assertSame('failed', $this->statusOf(new MailRelayCheck), 'the Mailtrap sandbox is a catcher');
    }

    public function test_hold_is_ignored_without_a_configured_key(): void
    {
        config(['app.mail_hold' => true]);
        $this->configureMail('log', 'smtp.example.com', 587, 'production');

        $this->assertSame('failed', $this->statusOf(new MailRelayCheck));
    }

    public function test_custom_catcher_lists_and_environments_are_respected(): void
    {
        $check = (new MailRelayCheck)
            ->catcherHosts([' Relay-Sink '])
            ->catcherPorts(['2525'])
            ->environments(['staging']);

        $this->configureMail('smtp', 'relay-sink.internal', 587, 'staging');
        $this->assertSame('failed', $this->statusOf($check));

        $this->configureMail('smtp', 'smtp.internal.example', 2525, 'staging');
        $this->assertSame('failed', $this->statusOf($check));

        $this->configureMail('smtp', 'mailpit', 1025, 'staging');
        $this->assertSame('ok', $this->statusOf($check), 'the default lists were replaced');

        $this->configureMail('smtp', 'relay-sink.internal', 587, 'production');
        $this->assertSame('ok', $this->statusOf($check), 'production is no longer monitored');
    }
}
