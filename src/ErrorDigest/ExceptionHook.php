<?php

declare(strict_types=1);

namespace IntrAgile\Observability\ErrorDigest;

use Illuminate\Foundation\Configuration\Exceptions;
use Throwable;

/**
 * Wires the error recorder into the exception handler.
 *
 * Call from the withExceptions() block in bootstrap/app.php. Applications with
 * a classic Handler class call app(ErrorRecorder::class)->record($e) directly.
 * Regular logging continues, because the callback does not stop propagation.
 */
final class ExceptionHook
{
    public static function register(Exceptions $exceptions): void
    {
        $exceptions->report(static function (Throwable $e): void {
            app(ErrorRecorder::class)->record($e);
        });
    }
}
