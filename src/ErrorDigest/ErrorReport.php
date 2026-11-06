<?php

declare(strict_types=1);

namespace IntrAgile\Observability\ErrorDigest;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A single reported exception waiting to be (or already) included in a Slack digest.
 *
 * @property int $id
 * @property string $exception_type
 * @property string $message
 * @property string|null $code
 * @property string $fingerprint
 * @property Carbon|null $sent_at
 * @property Carbon|null $created_at
 */
final class ErrorReport extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'observability_error_reports';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
