<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Auth\RequestPasswordReset;
use App\Filament\Pages\Auth\ResetPassword;
use App\Filament\Support\PanelSwitcher;
use App\Http\Middleware\ApplyOrganizationContext;
use App\Models\Organization;
use App\Support\Panel\BrandPalette;
use Filament\Actions\Action;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Panel de organizadores (ADR-0004). La organización es el tenant de Filament:
 * `/admin/{slug}`. `admin` es un slug reservado, así que no choca con las
 * rutas públicas `/{organization:slug}/...`. Sin registro de organizaciones
 * desde el panel: las crea la plataforma con un comando (plan 001, H-001-2).
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(Login::class)
            ->passwordReset(RequestPasswordReset::class, ResetPassword::class)
            ->renderHook(
                PanelsRenderHook::SCRIPTS_AFTER,
                fn (): View => view('filament.auth.throttle-notice'),
                scopes: [Login::class, RequestPasswordReset::class],
            )
            ->tenant(Organization::class, slugAttribute: 'slug')
            ->tenantMiddleware([ApplyOrganizationContext::class], isPersistent: true)
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->font('Roboto', provider: LocalFontProvider::class)
            ->darkMode(false)
            // Menú lateral más estrecho que el de Filament (20 rem) y contraíble a solo iconos;
            // en pantallas menores a 1024 px Filament ya lo muestra como cajón (plan 015, CA-015-14).
            ->sidebarWidth('15rem')
            ->collapsedSidebarWidth('4.5rem')
            ->sidebarCollapsibleOnDesktop()
            ->userMenuItems([PanelSwitcher::toPlatform()])
            ->bootUsing(fn () => self::defaultModalWidth())
            ->colors([
                'primary' => BrandPalette::primary(),
                'gray' => BrandPalette::gray(),
                'danger' => BrandPalette::danger(),
                'success' => BrandPalette::success(),
                'warning' => BrandPalette::warning(),
                'info' => BrandPalette::info(),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
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

    /**
     * Ancho `md` de todos los modales de acción del panel (plan 015, DA-5), definido
     * aquí y en ningún otro sitio. Filament usa `4xl` por omisión, demasiado ancho
     * en móvil. Va en `bootUsing` y no en `AppServiceProvider` para que solo corra
     * al arrancar este panel y no el de plataforma. `Action::configureUsing` alcanza
     * a las subclases (CreateAction, EditAction, ViewAction...) y corre antes de la
     * cadena de cada acción, así que un `->modalWidth()` explícito sigue ganando.
     * Los formularios largos se abren con `->slideOver()` en su propia acción.
     */
    private static function defaultModalWidth(): void
    {
        Action::configureUsing(fn (Action $action): Action => $action->modalWidth(Width::Medium));
    }
}
