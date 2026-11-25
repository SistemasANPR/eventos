<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Auth\RequestPasswordReset;
use App\Filament\Pages\Auth\ResetPassword;
use App\Filament\Pages\Organization\EditOrganizationProfile;
use App\Filament\Support\PanelSwitcher;
use App\Http\Middleware\ApplyOrganizationContext;
use App\Http\Middleware\EnsureTwoFactorConfirmed;
use App\Models\Organization;
use App\Support\Auth\TotpAuthentication;
use App\Support\Panel\BrandPalette;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

use function Filament\Support\original_request;

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
            // 2FA obligatoria (plan 014): el middleware decide por petición si aplica y a quién (TwoFactorRequirement).
            ->multiFactorAuthentication([TotpAuthentication::configured()], isRequired: true)
            ->multiFactorAuthenticationRequiredMiddlewareName(EnsureTwoFactorConfirmed::class)
            ->renderHook(
                PanelsRenderHook::SCRIPTS_AFTER,
                fn (): View => view('filament.auth.throttle-notice'),
                scopes: [Login::class, RequestPasswordReset::class],
            )
            ->renderHook(
                PanelsRenderHook::SIMPLE_LAYOUT_START,
                fn (): View => view('filament.auth.brand-panel'),
                scopes: [Login::class, RequestPasswordReset::class],
            )
            ->tenant(Organization::class, slugAttribute: 'slug')
            ->tenantMiddleware([ApplyOrganizationContext::class], isPersistent: true)
            ->tenantProfile(EditOrganizationProfile::class)
            ->navigationItems([self::organizationProfileItem()])
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->font('Roboto', provider: LocalFontProvider::class)
            ->darkMode(false)
            // Menú lateral más estrecho que el de Filament (20 rem) y contraíble a solo iconos;
            // en pantallas menores a 1024 px Filament ya lo muestra como cajón (plan 015, CA-015-14).
            ->sidebarWidth('13rem')
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
     * «Datos de la organización» como primera entrada del grupo «Organización» (sort 9, antes de Equipo,
     * que lleva 10): la pantalla ya existía, pero solo se alcanzaba desde el selector de organización.
     * Se ofrece a quien puede editarla (`EditOrganizationProfile::canView`); la Policy la niega en el servidor.
     */
    private static function organizationProfileItem(): NavigationItem
    {
        return NavigationItem::make(fn (): string => __('organizations.profile.title'))
            ->group(fn (): string => __('navigation.groups.organization'))
            ->icon(Heroicon::OutlinedBuildingOffice2)
            ->sort(9)
            ->visible(fn (): bool => ($tenant = Filament::getTenant()) instanceof Organization && EditOrganizationProfile::canView($tenant))
            ->url(fn (): string => EditOrganizationProfile::getUrl(tenant: Filament::getTenant()))
            ->activeWhen(fn (): bool => original_request()->routeIs('filament.admin.tenant.profile'));
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
