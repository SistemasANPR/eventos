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
    'throttled' => [
        'title' => 'Demasiados intentos',
        'body_one' => 'Vuelve a intentarlo en :minutes minuto.',
        'body_other' => 'Vuelve a intentarlo en :minutes minutos.',
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
    'two_factor_enabled' => [
        'subject' => 'Activaste la verificación en dos pasos',
        'greeting' => 'Hola',
        'line' => 'Se activó la verificación en dos pasos en tu cuenta.',
        'when' => 'Fecha: :date (UTC)',
        'ignore' => 'Si no fuiste tú, avisa de inmediato a un administrador de tu organización.',
    ],
    'two_factor_reset' => [
        'subject' => 'Reiniciaron tu verificación en dos pasos',
        'greeting' => 'Hola',
        'line' => 'Un administrador reinició la verificación en dos pasos de tu cuenta.',
        'when' => 'Fecha: :date (UTC)',
        'next' => 'La próxima vez que inicies sesión deberás configurarla de nuevo con tu aplicación autenticadora.',
        'ignore' => 'Si no lo esperabas, avisa de inmediato a un administrador de tu organización y cambia tu contraseña.',
    ],
    'login' => [
        'organization' => 'Asociación Nacional de Parques y Recreación',
        'kicker' => 'PLATAFORMA DE EVENTOS',
        'title' => 'Eventos',
        'tagline' => 'Convocatorias, evaluación de propuestas y programa de los encuentros de la ANPR, en un solo lugar.',
        'modules' => ['call' => 'Convocatoria', 'review' => 'Evaluación', 'program' => 'Programa'],
        'heading' => 'Entre a su cuenta',
        'subheading' => 'Use el correo con el que le invitaron a la plataforma.',
    ],
];
