<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Health;

use Illuminate\Contracts\Config\Repository;
use Spatie\Health\Notifications\CheckFailedNotification as SpatieCheckFailedNotification;
use Spatie\Health\Notifications\Notifiable;

/**
 * Health-check failure notification that is environment-aware.
 *
 * Enable it by using this class as the key in health.notifications.notifications.
 * Notifications are suppressed outside the configured environments unless overridden,
 * and the message identifies the environment, instance and deploy id.
 */
class CheckFailedNotification extends SpatieCheckFailedNotification
{
    /**
     * The environment policy runs before the parent decision on purpose: the parent
     * writes the throttle timestamp to the cache, so a suppressed run must never reach
     * it, otherwise it would consume the throttle window of a later real alert.
     */
    public function shouldSend(Notifiable $notifiable, string $channel): bool
    {
        $config = app(Repository::class);
        $environments = $config->get('observability.health.notifications.environments', ['production']);

        if (! NotificationPolicy::enabled(
            app()->environment(),
            is_array($environments) ? $environments : ['production'],
            $config->get('observability.health.notifications.override'),
        )) {
            return false;
        }

        return parent::shouldSend($notifiable, $channel);
    }

    /**
     * @return array<string, string>
     */
    public function transParameters(): array
    {
        $environment = app()->environment();

        $name = ($environment === 'production' ? '' : "[{$environment}] ").$this->instanceName();

        $deployId = app(Repository::class)->get('observability.deploy_id');

        if (is_scalar($deployId) && (string) $deployId !== '') {
            $name .= ' [deploy: '.$deployId.']';
        }

        return [
            'application_name' => $name,
            'env_name' => $environment,
        ];
    }

    private function instanceName(): string
    {
        $config = app(Repository::class);

        foreach (['observability.health.notifications.instance', 'app.name'] as $key) {
            $value = $config->get($key);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return 'Laravel application';
    }
}
