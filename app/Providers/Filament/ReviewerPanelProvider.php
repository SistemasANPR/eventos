<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\UnifiedLoginRedirect;
use App\Filament\Reviewer\Pages\ReviewerDashboard;
use App\Http\Middleware\ApplyOrganizationContext;
use App\Http\Middleware\EnsureTwoFactorConfirmed;
use App\Models\Organization;
use App\Support\Auth\TotpAuthentication;
use App\Support\Panel\BrandPalette;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Panel del evaluador (plan 011, H-011-3; ADR-0004: un panel por rol). La organización es el tenant de
 * Filament: `/reviewer/{slug}` (`reviewer` es un slug reservado). Lo usa quien tiene el rol `reviewer` y
 * no ve nada del panel de organizadores (CA-011-16): el acceso a este panel lo decide
 * `User::canAccessPanelId('reviewer')` y cada pantalla se acota a las asignaciones del propio evaluador
 * (`ReviewerAssignments`). Sin pantallas de organizador: no descubre recursos de otros paneles.
 */
class ReviewerPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('reviewer')
            ->path('reviewer')
            ->brandName(fn (): string => __('reviewer.brand'))
            // El acceso y la recuperación de contraseña son únicos y viven en `/admin` (plan 017).
            ->login(UnifiedLoginRedirect::class)
            // 2FA obligatoria (plan 014, §4.1): el rol `reviewer` ve datos de autores.
            ->multiFactorAuthentication([TotpAuthentication::configured()], isRequired: true)
            ->multiFactorAuthenticationRequiredMiddlewareName(EnsureTwoFactorConfirmed::class)
            ->tenant(Organization::class, slugAttribute: 'slug')
            ->tenantMiddleware([ApplyOrganizationContext::class], isPersistent: true)
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->font('Roboto', provider: LocalFontProvider::class)
            ->darkMode(false)
            ->colors([
                'primary' => BrandPalette::primary(),
                'gray' => BrandPalette::gray(),
                'danger' => BrandPalette::danger(),
                'success' => BrandPalette::success(),
                'warning' => BrandPalette::warning(),
                'info' => BrandPalette::info(),
            ])
            ->discoverResources(in: app_path('Filament/Reviewer/Resources'), for: 'App\Filament\Reviewer\Resources')
            ->pages([
                ReviewerDashboard::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
