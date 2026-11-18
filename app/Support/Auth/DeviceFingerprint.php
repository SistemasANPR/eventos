<?php

declare(strict_types=1);

namespace App\Support\Auth;

use Illuminate\Http\Request;

/**
 * Huella de dispositivo y descripción aproximada para el aviso de inicio de
 * sesión desde un dispositivo nuevo (CA-001-35 y CA-001-36).
 *
 * La huella combina la familia de navegador, la familia de sistema operativo y
 * la red (IPv4 /24, IPv6 /48), todo con HMAC-SHA256 con la clave de la
 * aplicación: no se guarda el agente de usuario ni la IP, y la base no basta
 * para reconstruirlos. Se ignoran las versiones para que actualizar el
 * navegador no dispare avisos, y se usa el prefijo de red (no la IP completa)
 * para tolerar el cambio de dirección dentro de la misma red. Cambiar de red
 * sí cuenta como dispositivo nuevo: es la opción estricta. Rotar `APP_KEY`
 * hace que todos los dispositivos se vean nuevos una vez (solo genera avisos).
 */
final class DeviceFingerprint
{
    private function __construct(
        public readonly string $hash,
        public readonly string $label,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $agent = (string) $request->userAgent();
        $browser = self::browser($agent);
        $system = self::system($agent);

        return new self(
            hash: hash_hmac('sha256', implode('|', [$browser, $system, self::network((string) $request->ip())]), (string) config('app.key')),
            label: "{$browser} / {$system}",
        );
    }

    private static function browser(string $agent): string
    {
        return match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') || str_contains($agent, 'Opera') => 'Opera',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Chrome/') || str_contains($agent, 'CriOS/') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            default => 'unknown',
        };
    }

    private static function system(string $agent): string
    {
        return match (true) {
            str_contains($agent, 'iPhone') || str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Mac OS X') || str_contains($agent, 'Macintosh') => 'macOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => 'unknown',
        };
    }

    private static function network(string $ip): string
    {
        $packed = inet_pton($ip);

        return match (true) {
            $packed === false => 'unknown',
            strlen($packed) === 4 => bin2hex(substr($packed, 0, 3)).'/24',
            default => bin2hex(substr($packed, 0, 6)).'/48',
        };
    }
}
