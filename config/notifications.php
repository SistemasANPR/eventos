<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Proveedor de correo transaccional
    |--------------------------------------------------------------------------
    |
    | `resend` en staging y producción (ADR-0009); `smtp` en local, donde entrega a
    | Mailpit. Un valor desconocido falla al arrancar el envío, no cae a otro proveedor.
    |
    */
    'driver' => env('NOTIFICATIONS_MAILER', 'smtp'),

];
