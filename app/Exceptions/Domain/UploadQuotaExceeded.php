<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use Illuminate\Http\Exceptions\ThrottleRequestsException;

/** Se superó la cuota de subidas por token o por IP (plan 007, DA-7, CA-007-13). Responde 429. */
final class UploadQuotaExceeded extends ThrottleRequestsException
{
    public const CODE = 'UPLOAD_QUOTA_EXCEEDED';

    public static function retryAfter(int $seconds): self
    {
        return new self(__('files.quota_exceeded'), null, ['Retry-After' => (string) $seconds]);
    }
}
