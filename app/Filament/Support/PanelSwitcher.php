<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;

/**
 * Enlace del menú de usuario para pasar de un panel al otro sin cerrar sesión
 * (plan 017, DA-2). Solo se ofrece a quien puede entrar a **ambos** paneles; el
 * acceso real lo sigue decidiendo cada panel, ocultar el enlace no autoriza nada.
 */
final class PanelSwitcher
{
    public static function toPlatform(): Action
    {
        return Action::make('switch-to-platform')
            ->label(fn (): string => __('access.switch.to_platform'))
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->url(fn (): string => Filament::getPanel('platform')->getUrl())
            ->visible(fn (): bool => self::canEnterBoth());
    }

    public static function toOrganization(): Action
    {
        return Action::make('switch-to-organization')
            ->label(fn (): string => __('access.switch.to_organization'))
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->url(fn (): string => Filament::getPanel('admin')->getUrl())
            ->visible(fn (): bool => self::canEnterBoth());
    }

    private static function canEnterBoth(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $user->canAccessPanelId('platform') && $user->canAccessPanelId('admin');
    }
}
