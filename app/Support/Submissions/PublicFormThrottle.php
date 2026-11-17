<?php

declare(strict_types=1);

namespace App\Support\Submissions;

use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Límites de los formularios públicos de propuestas (plan 006, H-006-2, DA-5).
 *
 * Valores iniciales: 5 envíos por IP cada 10 minutos, 3 acuses por correo cada hora,
 * 3 solicitudes de enlace por correo cada hora y 10 por IP cada hora (`config/submissions.php`).
 * El correo se normaliza y se guarda solo como HMAC con la llave de la aplicación, de modo
 * que ni la caché ni los registros contengan el correo en claro. Se cuenta con `hit()` y se
 * decide con su resultado: comprobar y luego contar dejaría pasar ráfagas paralelas. La respuesta
 * es la misma exista o no el correo.
 */
final class PublicFormThrottle
{
    /** @throws ThrottleRequestsException */
    public function hitSubmission(string $ip, string $email): void
    {
        $this->hit('submission-ip:'.$ip, $this->limit('submission_per_ip'));
        $this->hit(self::emailKey('submission', $email), $this->limit('received_per_email'));
    }

    /** @throws ThrottleRequestsException */
    public function hitAccessLink(string $ip, string $email): void
    {
        $this->hit('access-link-ip:'.$ip, $this->limit('access_link_per_ip'));
        $this->hit(self::emailKey('access-link', $email), $this->limit('access_link_per_email'));
    }

    public static function emailKey(string $scope, string $email): string
    {
        $normalized = mb_strtolower(trim($email));

        return $scope.'-email:'.hash_hmac('sha256', $normalized, (string) config('app.key'));
    }

    /**
     * @param  array{attempts: int, decay_seconds: int}  $limit
     *
     * @throws ThrottleRequestsException
     */
    private function hit(string $key, array $limit): void
    {
        if (RateLimiter::hit($key, $limit['decay_seconds']) > $limit['attempts']) {
            throw new ThrottleRequestsException(
                headers: ['Retry-After' => (string) RateLimiter::availableIn($key)],
            );
        }
    }

    /** @return array{attempts: int, decay_seconds: int} */
    private function limit(string $name): array
    {
        /** @var array{attempts: int, decay_seconds: int} $limit */
        $limit = config('submissions.limits.'.$name);

        return $limit;
    }
}
