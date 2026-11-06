<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTP basic auth for the Prometheus scrape endpoint that fails closed.
 *
 * Credentials come from `observability.prometheus.basic_auth`. The password is taken from
 * `password` or, when that is empty, from the trimmed contents of `password_file` (a mounted
 * secret). When neither yields a non-empty password, every request is rejected: a missing
 * secret must never leave the metrics endpoint open.
 *
 * Whether this middleware is attached at all is decided by the service provider through the
 * `enabled` key; once attached it always enforces authentication.
 */
final class PrometheusBasicAuth
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expectedPassword = $this->expectedPassword();

        if ($expectedPassword === '') {
            return $this->unauthorized();
        }

        $username = config('observability.prometheus.basic_auth.username');
        $expectedUsername = is_string($username) ? $username : '';

        // Evaluate both comparisons before branching so timing does not reveal which one failed.
        $usernameMatches = hash_equals($expectedUsername, (string) $request->getUser());
        $passwordMatches = hash_equals($expectedPassword, (string) $request->getPassword());

        if (! $usernameMatches || ! $passwordMatches) {
            return $this->unauthorized();
        }

        return $next($request);
    }

    /**
     * Resolves the configured password; an empty string means no usable password exists.
     */
    private function expectedPassword(): string
    {
        $password = config('observability.prometheus.basic_auth.password');

        if (is_string($password) && $password !== '') {
            return $password;
        }

        $file = config('observability.prometheus.basic_auth.password_file');

        if (! is_string($file) || $file === '' || ! is_file($file) || ! is_readable($file)) {
            return '';
        }

        $contents = @file_get_contents($file);

        return $contents === false ? '' : trim($contents);
    }

    private function unauthorized(): Response
    {
        return response()->json(
            ['message' => 'Unauthorized'],
            Response::HTTP_UNAUTHORIZED,
            ['WWW-Authenticate' => 'Basic realm="Prometheus"'],
        );
    }
}
