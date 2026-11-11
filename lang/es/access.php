<?php

declare(strict_types=1);

return [
    'switch' => [
        'to_platform' => 'Ir al panel de plataforma',
        'to_organization' => 'Ir a mi organización',
    ],

    'reset_request' => [
        'sent' => [
            'title' => 'Revisa tu correo',
            'body' => 'Si el correo está registrado, te enviamos un enlace para restablecer la contraseña.',
        ],
    ],
    'reset_invalid' => 'El enlace no es válido o ya caducó. Solicita uno nuevo.',
    'password_reset' => [
        'subject' => 'Restablece tu contraseña',
        'greeting' => 'Hola',
        'line' => 'Recibimos una solicitud para restablecer la contraseña de tu cuenta.',
        'action' => 'Restablecer contraseña',
        'expiry' => 'El enlace caduca en :minutes minutos y solo se puede usar una vez.',
        'ignore' => 'Si no lo solicitaste, ignora este mensaje: tu contraseña no cambia.',
    ],
    'new_device' => [
        'subject' => 'Nuevo inicio de sesión en tu cuenta',
        'greeting' => 'Hola',
        'line' => 'Iniciaron sesión en tu cuenta desde un dispositivo que no reconocemos.',
        'when' => 'Fecha: :date (UTC)',
        'device' => 'Dispositivo aproximado: :device',
        'ignore' => 'Si fuiste tú, no hace falta que hagas nada. Si no, cambia tu contraseña de inmediato.',
    ],
];
