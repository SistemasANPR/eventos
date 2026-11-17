<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Límites de los formularios públicos de propuestas (plan 006, DA-5)
    |--------------------------------------------------------------------------
    |
    | Valores iniciales; se ajustan con el ensayo de carga del plan 010.
    | Una IP compartida en un congreso puede alcanzar el límite por IP.
    |
    */

    'limits' => [
        'submission_per_ip' => ['attempts' => 5, 'decay_seconds' => 600],
        'received_per_email' => ['attempts' => 3, 'decay_seconds' => 3600],
        'access_link_per_email' => ['attempts' => 3, 'decay_seconds' => 3600],
        'access_link_per_ip' => ['attempts' => 10, 'decay_seconds' => 3600],
    ],

    /*
    |--------------------------------------------------------------------------
    | Acceso del autor sin cuenta (plan 006, DA-3)
    |--------------------------------------------------------------------------
    |
    | Plazos propuestos; se ajustan tras el primer ensayo. Un correo recibe un enlace por
    | propuesta activa, con este máximo.
    |
    */

    'author' => [
        'access_link_minutes' => 30,
        'session_minutes' => 60,
        'max_links_per_email' => 5,
    ],

];
