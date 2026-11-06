<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Health;

/**
 * Decides whether health-failure notifications may be sent in the current environment.
 *
 * Alerts from non-production environments are usually noise, so by default only the
 * configured environments notify. An explicit override (HEALTH_NOTIFICATIONS_ENABLED)
 * wins over the environment list in both directions. A value that is not a recognised
 * boolean token (a typo such as "ture") is ignored and the environment list decides, so
 * a misconfiguration can never silence production alerts.
 */
final class NotificationPolicy
{
    private function __construct() {}

    /**
     * @param  array<array-key, mixed>  $environments
     */
    public static function enabled(string $environment, array $environments, mixed $override): bool
    {
        // empty() is deliberately avoided: '0' is an explicit "false" override, not an absent one.
        if ($override !== null && $override !== '') {
            $parsed = filter_var($override, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if ($parsed !== null) {
                return $parsed;
            }
        }

        return in_array($environment, $environments, true);
    }
}
