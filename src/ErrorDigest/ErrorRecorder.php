<?php

declare(strict_types=1);

namespace IntrAgile\Observability\ErrorDigest;

use Illuminate\Support\Facades\Log;
use IntrAgile\Observability\Support\SqlMessageRedactor;
use Throwable;

/**
 * Persists reported exceptions for the Slack error digest.
 *
 * Does nothing while observability.error_digest.enabled is false, so a
 * registered hook never writes when the digest is switched off.
 *
 * Runs inside the exception reporting pipeline, so it must never throw: a
 * failure is logged as a warning (without a stack trace) and swallowed. A
 * static guard prevents recursion when storing the report itself raises an
 * exception that gets reported again.
 */
final class ErrorRecorder
{
    /** Stays below the 64 KB limit of a MySQL TEXT column. */
    private const MESSAGE_MAX_BYTES = 60000;

    private const TYPE_MAX_CHARS = 255;

    private const CODE_MAX_CHARS = 64;

    private static bool $recording = false;

    public function record(Throwable $e): void
    {
        if (self::$recording || ! $this->enabled()) {
            return;
        }

        self::$recording = true;

        try {
            $type = mb_substr($e::class, 0, self::TYPE_MAX_CHARS);
            $message = mb_strcut(SqlMessageRedactor::redact($e->getMessage()), 0, self::MESSAGE_MAX_BYTES, 'UTF-8');

            $report = new ErrorReport;
            $report->exception_type = $type;
            $report->message = $message;
            $report->code = $this->code($e);
            $report->fingerprint = sha1($type."\n".$message);
            $report->save();
        } catch (Throwable $failure) {
            $this->warn($e, $failure);
        } finally {
            self::$recording = false;
        }
    }

    private function enabled(): bool
    {
        try {
            return (bool) config('observability.error_digest.enabled', false);
        } catch (Throwable) {
            return false;
        }
    }

    private function code(Throwable $e): ?string
    {
        // PDOException carries the SQLSTATE string here, other exceptions an int.
        $code = (string) $e->getCode();

        if ($code === '' || $code === '0') {
            return null;
        }

        return mb_substr($code, 0, self::CODE_MAX_CHARS);
    }

    private function warn(Throwable $e, Throwable $failure): void
    {
        try {
            Log::warning('Failed to record exception report.', [
                'exception' => $e::class,
                'reason' => SqlMessageRedactor::redact($failure->getMessage()),
            ]);
        } catch (Throwable) {
            // The logger itself is broken; there is nowhere left to report to.
        }
    }
}
