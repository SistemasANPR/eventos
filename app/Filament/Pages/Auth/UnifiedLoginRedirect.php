<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use App\Models\User;
use App\Support\Auth\PortalDestination;
use Filament\Facades\Filament;
use Filament\Pages\SimplePage;
use Filament\Support\Components\Attributes\Unexposed;

/**
 * Acceso del panel de plataforma (plan 017): no tiene formulario propio, solo
 * envía al acceso único. Una persona ya autenticada va a su portal. Filament
 * exige que cada panel tenga una página de acceso para redirigir a los invitados.
 */
class UnifiedLoginRedirect extends SimplePage
{
    #[Unexposed]
    public function mount(): void
    {
        $user = Filament::auth()->user();

        $this->redirect($user instanceof User && $user->canAccessAnyPanel()
            ? PortalDestination::home($user)
            : Filament::getPanel('admin')->getLoginUrl());
    }
}
