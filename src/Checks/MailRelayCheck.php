<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Checks;

use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Fails when mail in a monitored environment would never reach a real recipient.
 *
 * A mail catcher is not an outage: the SMTP conversation succeeds, Laravel considers the message
 * sent, no exception is raised and nothing is logged. Every mail is reported as delivered and
 * reaches nobody, so the application itself is never in a position to notice. This check covers
 * that blind spot.
 *
 * It stays deliberately narrow: outside the monitored environments a catcher or the `log` mailer is
 * the correct setup, and a check that goes red there is one people learn to ignore.
 */
final class MailRelayCheck extends Check
{
    /**
     * Mailers that deliver to nobody.
     */
    private const SILENT_MAILERS = ['log', 'array', 'null'];

    /** @var list<string> */
    private array $environments = ['production'];

    /**
     * Host labels that mean "this address collects mail instead of forwarding it".
     *
     * Mailtrap is listed by its sandbox host only: the same vendor also runs production relays
     * (`live.smtp.mailtrap.io`, `bulk.smtp.mailtrap.io`), so the bare `mailtrap` label would flag
     * working relays.
     *
     * @var list<string>
     */
    private array $catcherHosts = ['mailhog', 'mailpit', 'sandbox.smtp.mailtrap.io', 'mailcatcher', 'maildev', 'smtp4dev'];

    /**
     * Ports no relay listens on: 1025 is the conventional catcher SMTP port, 1080 and 8025 are
     * catcher web interfaces. A relay is reached on 25, 465 or 587.
     *
     * @var list<int>
     */
    private array $catcherPorts = [1025, 1080, 8025];

    private ?string $holdConfigKey = null;

    /**
     * @param  array<array-key, string>  $environments
     */
    public function environments(array $environments): static
    {
        $this->environments = array_values($environments);

        return $this;
    }

    /**
     * @param  array<array-key, string>  $hosts
     */
    public function catcherHosts(array $hosts): static
    {
        $this->catcherHosts = array_values(array_map(
            static fn (string $host): string => strtolower(trim($host)),
            $hosts,
        ));

        return $this;
    }

    /**
     * @param  array<array-key, int|string>  $ports
     */
    public function catcherPorts(array $ports): static
    {
        $this->catcherPorts = array_values(array_map(
            static fn (int|string $port): int => (int) $port,
            $ports,
        ));

        return $this;
    }

    /**
     * Config key whose truthy value marks the silence as a deliberate decision rather than a fault.
     * A non-empty string value is treated as the stated reason and included in the result message,
     * except for boolean spellings: `0`, `false`, `no` and `off` switch the hold off, while `1`,
     * `true`, `yes` and `on` switch it on without stating a reason.
     */
    public function holdConfigKey(?string $key): static
    {
        $this->holdConfigKey = $key === null || trim($key) === '' ? null : $key;

        return $this;
    }

    public function run(): Result
    {
        $reason = $this->whyNothingWouldArrive();

        $result = Result::make()
            ->shortSummary($reason === null ? 'relay' : 'catcher')
            ->meta([
                'mailer' => $this->configString('mail.default'),
                'host' => $this->configString('mail.mailers.smtp.host'),
                'port' => $this->configString('mail.mailers.smtp.port'),
                'held' => false,
            ]);

        if ($reason === null) {
            return $result->ok('Mail goes to a relay.');
        }

        if (! app()->environment($this->environments)) {
            return $result->ok($reason.', which is expected outside the monitored environments.');
        }

        // A stated hold is a decision, not a fault, so it reports ok instead of warning: a warning
        // still counts as failing for the health command's exit code.
        $hold = $this->holdConfigKey === null ? null : config($this->holdConfigKey);
        $statedReason = $this->statedHoldReason($hold);

        if ($hold === true || $statedReason !== null || $this->isTruthyHoldString($hold)) {
            $message = $statedReason !== null
                ? 'Mail deliberately goes nowhere: '.$statedReason
                : 'Mail deliberately goes nowhere: '.$reason.'.';

            return $result
                ->shortSummary('held')
                ->appendMeta(['held' => true])
                ->ok($message);
        }

        return $result->failed(
            'Mail would reach no one in this environment: '.$reason.'. A catcher accepts the message, '
            .'so every mail is reported as sent and delivered to nobody. Configure a real relay, or set '
            .'the hold config key if the silence is deliberate.'
        );
    }

    /**
     * Why a message would not arrive, or null if it would.
     *
     * Phrased as a sentence rather than a boolean because the remedy differs: an empty host means
     * mail was never configured, a catcher host means one was inherited from a development setup.
     * Public so that status commands can report the same verdict the check uses.
     */
    public function whyNothingWouldArrive(): ?string
    {
        $mailer = config('mail.default');

        // `null` has two spellings: Dotenv reads an unquoted `null` as PHP null, while a config
        // default may hold the string. Both mean the same thing.
        if ($mailer === null || $mailer === '' || in_array($mailer, self::SILENT_MAILERS, true)) {
            return 'the mailer is '.var_export($mailer, true).', which delivers to nobody';
        }

        // Only the SMTP transport is inspected further. An unrecognised transport (ses, postmark, a
        // custom one) is assumed to deliver: guessing otherwise would raise false alarms.
        if ($mailer !== 'smtp') {
            return null;
        }

        $host = strtolower(trim($this->configString('mail.mailers.smtp.host')));

        if ($host === '' || $host === 'null') {
            return 'the SMTP host is empty, so there is no relay to post to';
        }

        if ($this->isCatcherHost($host)) {
            return 'the SMTP host is '.$host.', which is a mail catcher rather than a relay';
        }

        // An independent second signal: a catcher can be renamed while keeping its port.
        $port = config('mail.mailers.smtp.port');

        if (is_numeric($port) && in_array((int) $port, $this->catcherPorts, true)) {
            return 'the SMTP port is '.(int) $port.', which no relay listens on';
        }

        if (str_starts_with($host, '127.') || in_array($host, ['localhost', '::1', '[::1]'], true)) {
            return 'the SMTP host is '.$host.', which is this machine itself';
        }

        return null;
    }

    /**
     * Matches whole dot- or hyphen-separated labels only, never substrings, so a real relay such as
     * `mail.example.com` or `mailhogwarts.example` is not mistaken for a catcher. A configured entry
     * may itself contain separators (`relay-sink`) and then matches that run of labels.
     */
    private function isCatcherHost(string $host): bool
    {
        foreach ($this->catcherHosts as $catcher) {
            if ($catcher === '') {
                continue;
            }

            if (preg_match('/(?:^|[.\-])'.preg_quote($catcher, '/').'(?:$|[.\-])/', $host) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The free-text reason a string hold value states, or null when it states none. Environment
     * variables arrive as strings (`MAIL_HOLD=0` reads as '0'), so boolean spellings are not reasons.
     */
    private function statedHoldReason(mixed $hold): ?string
    {
        if (! is_string($hold) || trim($hold) === '') {
            return null;
        }

        if (filter_var(trim($hold), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== null) {
            return null;
        }

        return trim($hold);
    }

    private function isTruthyHoldString(mixed $hold): bool
    {
        return is_string($hold)
            && filter_var(trim($hold), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
    }

    private function configString(string $key): string
    {
        $value = config($key);

        return is_scalar($value) ? (string) $value : '';
    }
}
