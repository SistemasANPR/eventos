<?php

declare(strict_types=1);

/*
 * Panel de plataforma (plan 016, DA-1). Apagado por defecto fuera de local y
 * pruebas: hasta que exista la 2FA del plan 014 no debe habilitarse en
 * producción. Con el panel apagado, todas sus rutas responden 404.
 */
return [
    'enabled' => (bool) env('PLATFORM_PANEL_ENABLED', in_array(env('APP_ENV'), ['local', 'testing'], true)),
];
