<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Support;

/**
 * Refuses to run Artisan commands as root in the configured environments.
 *
 * Running Artisan manually as root (for example from a `docker exec` root shell) leaves
 * cache and storage files owned by root. The web server and queue workers, which run as an
 * unprivileged user such as `www-data`, can no longer write to them, which typically surfaces
 * later as "not writable" errors far away from the command that caused them.
 *
 * The guard is only active in the configured environments because CI pipelines commonly run
 * Artisan as root with a non-production environment; guarding every environment would break
 * those pipelines.
 *
 * CI note: explicit Artisan calls are not the only ones that count. `composer install` runs
 * `php artisan package:discover` from its post-autoload-dump hook, so the first Artisan
 * command of a job is often issued through Composer. Set APP_ENV to a non-guarded value
 * before `composer install`, not only before the explicit Artisan calls.
 *
 * A deliberate, one-off override is available by setting the configured allow variable to 1
 * (e.g. `ARTISAN_ALLOW_ROOT=1`). The caller is responsible for converting the raw variable
 * value to a boolean, e.g. with `filter_var($value, FILTER_VALIDATE_BOOLEAN)`.
 */
final class RootExecutionGuard
{
    /**
     * Decide whether the current process must be stopped.
     *
     * @param  int|null  $euid  Effective user id, or null when it cannot be determined.
     * @param  string  $environment  Current application environment.
     * @param  array<array-key, mixed>  $environments  Environments in which root execution is refused.
     * @param  bool  $allowed  Whether the explicit override is set.
     */
    public static function shouldBlock(?int $euid, string $environment, array $environments, bool $allowed): bool
    {
        if ($euid !== 0) {
            return false;
        }

        if ($allowed) {
            return false;
        }

        return in_array($environment, $environments, true);
    }

    /**
     * Build the explanation printed when execution is refused.
     */
    public static function message(string $allowEnv): string
    {
        return implode(PHP_EOL, [
            'Artisan commands must not be run as root in this environment.',
            'Files created by root stay owned by root, and the web server user',
            '(e.g. www-data) can no longer write to them, which causes "not writable" errors.',
            '',
            'Run the command as the web server user instead:',
            '    sudo -u www-data php artisan <command>',
            '',
            'If you deliberately need to run it as root, as a one-off override:',
            "    {$allowEnv}=1 php artisan <command>",
        ]);
    }

    /**
     * Return the effective user id, or null when the posix extension is unavailable.
     */
    public static function currentEuid(): ?int
    {
        return function_exists('posix_geteuid') ? posix_geteuid() : null;
    }
}
