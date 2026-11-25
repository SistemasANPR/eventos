{{--
    Panel de marca de las pantallas de acceso (inicio de sesión y recuperación). Se inyecta con el
    gancho SIMPLE_LAYOUT_START y el tema lo coloca a la izquierda en pantallas anchas; en móvil queda
    como una cabecera compacta. Es decorativo: los círculos van ocultos a lectores de pantalla.
--}}
<aside class="eventos-auth-brand">
    <div class="eventos-auth-brand-logo">
        <span class="eventos-auth-brand-mark" aria-hidden="true">AR</span>
        <span class="eventos-auth-brand-org">{{ __('access.login.organization') }}</span>
    </div>

    <div class="eventos-auth-brand-body">
        <p class="eventos-auth-brand-kicker">{{ __('access.login.kicker') }}</p>
        <p class="eventos-auth-brand-title">{{ __('access.login.title') }}</p>
        <p class="eventos-auth-brand-tagline">{{ __('access.login.tagline') }}</p>
    </div>

    <ul class="eventos-auth-brand-modules">
        <li><x-filament::icon icon="heroicon-o-megaphone" class="eventos-auth-brand-icon" />{{ __('access.login.modules.call') }}</li>
        <li><x-filament::icon icon="heroicon-o-scale" class="eventos-auth-brand-icon" />{{ __('access.login.modules.review') }}</li>
        <li><x-filament::icon icon="heroicon-o-calendar-days" class="eventos-auth-brand-icon" />{{ __('access.login.modules.program') }}</li>
    </ul>

    <span class="eventos-auth-brand-circle eventos-auth-brand-circle-ring" aria-hidden="true"></span>
    <span class="eventos-auth-brand-circle eventos-auth-brand-circle-dot" aria-hidden="true"></span>
</aside>
