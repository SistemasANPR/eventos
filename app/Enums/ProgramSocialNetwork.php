<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Redes que puede tener la ficha de un ponente (plan 021, DA-17). Cada una fija los dominios que
 * acepta su dirección; el sitio web acepta cualquier dominio público (no una IP ni `localhost`).
 */
enum ProgramSocialNetwork: string
{
    case LinkedIn = 'linkedin';
    case X = 'x';
    case Instagram = 'instagram';
    case Facebook = 'facebook';
    case YouTube = 'youtube';
    case TikTok = 'tiktok';
    case Website = 'website';

    /** @return list<string> dominios base aceptados; vacío en el sitio web (cualquiera). */
    public function domains(): array
    {
        return match ($this) {
            self::LinkedIn => ['linkedin.com'],
            self::X => ['x.com', 'twitter.com'],
            self::Instagram => ['instagram.com'],
            self::Facebook => ['facebook.com', 'fb.com'],
            self::YouTube => ['youtube.com', 'youtu.be'],
            self::TikTok => ['tiktok.com'],
            self::Website => [],
        };
    }

    /** ¿El dominio es el de la red (o un subdominio suyo, como `www.` o `mx.`)? */
    public function acceptsHost(string $host): bool
    {
        $host = strtolower(rtrim($host, '.'));

        if ($this === self::Website) {
            return self::isPublicHostname($host);
        }

        foreach ($this->domains() as $domain) {
            if ($host === $domain || str_ends_with($host, ".{$domain}")) {
                return true;
            }
        }

        return false;
    }

    /** Nombre con punto, sin IP ni nombres internos (`localhost`, `.local`, `.internal`). */
    private static function isPublicHostname(string $host): bool
    {
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false || str_starts_with($host, '[')) {
            return false;
        }

        if (! str_contains($host, '.') || preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $host) !== 1) {
            return false;
        }

        return preg_match('/\.(localhost|local|internal|localdomain|lan|home|corp|test|invalid|example)$/', $host) !== 1;
    }
}
