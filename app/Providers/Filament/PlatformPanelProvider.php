<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\UnifiedLoginRedirect;
use App\Filament\Support\PanelSwitcher;
use App\Http\Middleware\EnsurePlatformPanelEnabled;
use App\Support\Panel\BrandPalette;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Panel de plataforma (plan 016, ADR-0004): el equipo de la plataforma
 * (`is_platform_admin`) ve organizaciones, usuarios, eventos y auditoría. No
 * tiene *tenant*: sin contexto de organización, cualquier consulta a un modelo
 * con organización falla cerrada, y lo que cruza organizaciones pasa por
 * `withoutOrganizationScope()` auditado. `platform` es un slug reservado.
 */
class PlatformPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('platform')
            ->path('platform')
            ->brandName(fn (): string => __('platform.brand'))
            // El acceso y la recuperación de contraseña son únicos y viven en `/admin` (plan 017).
            ->login(UnifiedLoginRedirect::class)
            ->middleware([EnsurePlatformPanelEnabled::class], isPersistent: true)
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->font('Roboto', provider: LocalFontProvider::class)
            ->darkMode(false)
            ->userMenuItems([PanelSwitcher::toOrganization()])
            ->colors([
                'primary' => BrandPalette::primary(),
                'gray' => BrandPalette::gray(),
                'danger' => BrandPalette::danger(),
                'success' => BrandPalette::success(),
                'warning' => BrandPalette::warning(),
                'info' => BrandPalette::info(),
            ])
            ->discoverResources(in: app_path('Filament/Platform/Resources'), for: 'App\Filament\Platform\Resources')
            ->pages([
                Dashboard::class,
            ])
            ->widgets([
                AccountWidget::class,
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
