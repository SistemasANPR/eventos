<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Models\User;
use Filament\Facades\Filament;

/**
 * Adónde se envía a una persona tras iniciar sesión (plan 017): a la URL a la que
 * iba **solo si** es del propio sitio, cae en un panel y la persona puede entrar a
 * él; si no, al inicio de su portal. Nunca se redirige fuera del sitio ni a un
 * panel que respondería 403.
 */
final class PortalDestination
{
    /** @param  string|null  $intended  URL guardada por el middleware de autenticación */
    public static function for(User $user, ?string $intended): string
    {
        return self::allowedIntended($user, $intended) ?? self::home($user);
    }

    /** Inicio del portal de la persona; sin acceso a ningún panel, el acceso único. */
    public static function home(User $user): string
    {
        $loginUrl = (string) Filament::getPanel('admin')->getLoginUrl();
        $panelId = $user->homePanelId();

        if ($panelId === null) {
            return $loginUrl;
        }

        $panel = Filament::getPanel($panelId);

        // Con *tenant* se pasa la organización explícita: Filament la deduce del panel
        // en curso, que aquí puede ser otro (p. ej. se llegó desde `/platform/login`).
        $tenant = $panel->hasTenancy() ? $user->getTenants($panel)->first() : null;

        return $panel->getUrl($tenant) ?? $loginUrl;
    }

    private static function allowedIntended(User $user, ?string $intended): ?string
    {
        if ($intended === null || $intended === '') {
            return null;
        }

        $parts = parse_url($intended);

        if (! is_array($parts)) {
            return null;
        }

        if (isset($parts['scheme']) && ! in_array($parts['scheme'], ['http', 'https'], true)) {
            return null;
        }

        if (isset($parts['host']) && $parts['host'] !== request()->getHost()) {
            return null;
        }

        $path = '/'.ltrim($parts['path'] ?? '/', '/');

        foreach (Filament::getPanels() as $panel) {
            $prefix = '/'.trim($panel->getPath(), '/');

            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return $user->canAccessPanelId($panel->getId()) ? $intended : null;
            }
        }

        return null;
    }
}
