<?php

declare(strict_types=1);

namespace App\Support\Files;

use App\Exceptions\Domain\UploadQuotaExceeded;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Cuotas de subida pública (plan 007, DA-7): 10 por `upload_token` y 30 por IP
 * cada hora. Las claves incluyen la organización (app/CLAUDE.md §2) y el token
 * solo entra como hash. Se cuenta el intento antes de decidir: contar después
 * dejaría pasar una ráfaga paralela.
 */
final class UploadQuota
{
    public const MAX_PER_TOKEN = 10;

    public const MAX_PER_IP = 30;

    public const DECAY_SECONDS = 3600;

    /** @throws UploadQuotaExceeded si el token o la IP superaron su cuota (429) */
    public function hit(string $organizationId, string $token, string $ip): void
    {
        $this->hitBucket("submission-upload:{$organizationId}:token:".UploadToken::hash($token), self::MAX_PER_TOKEN);
        $this->hitBucket("submission-upload:{$organizationId}:ip:{$ip}", self::MAX_PER_IP);
    }

    private function hitBucket(string $key, int $max): void
    {
        if (RateLimiter::hit($key, self::DECAY_SECONDS) > $max) {
            throw UploadQuotaExceeded::retryAfter(RateLimiter::availableIn($key));
        }
    }
}
