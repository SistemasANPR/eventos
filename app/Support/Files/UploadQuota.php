<?php

declare(strict_types=1);

namespace App\Support\Files;

use App\Exceptions\Domain\UploadQuotaExceeded;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Cuotas de subida pública (plan 006 CA-006-28, plan 007 DA-7): por `upload_token` y por IP,
 * con los valores de `config/submissions.php` (`upload_quota`). Las claves incluyen la
 * organización (app/CLAUDE.md §2) y el token solo entra como hash. Se cuenta el intento
 * antes de decidir: contar después dejaría pasar una ráfaga paralela.
 */
final class UploadQuota
{
    /** @throws UploadQuotaExceeded si el token o la IP superaron su cuota (429) */
    public function hit(string $organizationId, string $token, string $ip): void
    {
        $this->hitBucket("submission-upload:{$organizationId}:token:".UploadToken::hash($token), 'per_token');
        $this->hitBucket("submission-upload:{$organizationId}:ip:{$ip}", 'per_ip');
    }

    private function hitBucket(string $key, string $limit): void
    {
        $max = (int) config("submissions.upload_quota.{$limit}.attempts");
        $decay = (int) config("submissions.upload_quota.{$limit}.decay_seconds");

        if (RateLimiter::hit($key, $decay) > $max) {
            throw UploadQuotaExceeded::retryAfter(RateLimiter::availableIn($key));
        }
    }
}
