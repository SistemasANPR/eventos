<?php

declare(strict_types=1);

/*
 * Multiplica los intentos de los límites de abajo. Por defecto 1 (los valores del plan 006). En
 * staging o en pruebas manuales se sube (por ejemplo 100) para no quedar bloqueado; en producción
 * se deja en 1. Nunca baja de 1.
 */
$multiplier = max(1, (int) env('SUBMISSIONS_LIMIT_MULTIPLIER', 1));

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
        'submission_per_ip' => ['attempts' => 5 * $multiplier, 'decay_seconds' => 600],
        'received_per_email' => ['attempts' => 3 * $multiplier, 'decay_seconds' => 3600],
        'access_link_per_email' => ['attempts' => 3 * $multiplier, 'decay_seconds' => 3600],
        'access_link_per_ip' => ['attempts' => 10 * $multiplier, 'decay_seconds' => 3600],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cuotas de subida de archivos antes del envío (plan 006, DA-9; plan 007, DA-7)
    |--------------------------------------------------------------------------
    |
    | Intentos por token de carga y por IP dentro de la ventana. Superarlas responde 429 y no
    | guarda el archivo. Se ajustan con el ensayo de carga del plan 010.
    |
    */

    'upload_quota' => [
        'per_token' => ['attempts' => 10 * $multiplier, 'decay_seconds' => 3600],
        'per_ip' => ['attempts' => 30 * $multiplier, 'decay_seconds' => 3600],
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

    /*
    |--------------------------------------------------------------------------
    | Recordatorio de cierre de la convocatoria (plan 012, H-012-4)
    |--------------------------------------------------------------------------
    |
    | Días de calendario (en la zona horaria del evento) antes del cierre a partir de los cuales
    | la tarea diaria avisa, una sola vez, a los autores con propuestas enviadas.
    |
    */

    'closing_reminder_days' => 3,

];
