<?php

declare(strict_types=1);

/*
 * Descripción de cada notificación del catálogo (plan 008). Se muestra en la
 * pantalla del catálogo del panel; no es el texto del correo.
 */
return [
    'invalid_variable' => 'Variables no permitidas: :variables.',
    'auth' => [
        'password_reset' => [
            'name' => 'Restablecer contraseña',
            'description' => 'Enlace firmado y de un solo uso para fijar una contraseña nueva.',
            'trigger' => 'Alguien pide restablecer su contraseña.',
            'recipient' => 'La persona que pidió el restablecimiento.',
        ],
        'new_device_login' => [
            'name' => 'Inicio de sesión desde un dispositivo nuevo',
            'description' => 'Aviso de seguridad con la fecha y el dispositivo aproximado.',
            'trigger' => 'Se inicia sesión desde un dispositivo no registrado.',
            'recipient' => 'El titular de la cuenta.',
        ],
    ],
    'team' => [
        'set_password' => [
            'name' => 'Alta en el equipo: fijar contraseña',
            'description' => 'Invitación con el enlace para establecer la contraseña de una cuenta nueva.',
            'trigger' => 'Se da de alta a una persona nueva en una organización.',
            'recipient' => 'La persona dada de alta.',
        ],
        'membership_added' => [
            'name' => 'Alta en el equipo: cuenta existente',
            'description' => 'Aviso de que una organización añadió a una persona que ya tenía cuenta.',
            'trigger' => 'Se añade a una organización a alguien que ya tiene cuenta.',
            'recipient' => 'La persona añadida.',
        ],
    ],
    'submission' => [
        'received' => [
            'name' => 'Acuse de recibo de la propuesta',
            'description' => 'Confirma que la propuesta se recibió y la identifica con una referencia.',
            'trigger' => 'Se envía una propuesta.',
            'recipient' => 'La persona que envió la propuesta.',
        ],
        'access_link' => [
            'name' => 'Enlace de acceso del autor',
            'description' => 'Enlace de un solo uso para consultar la propuesta enviada.',
            'trigger' => 'El autor pide su enlace de acceso.',
            'recipient' => 'La persona que envió la propuesta.',
        ],
        'new_for_organizers' => [
            'name' => 'Propuesta nueva para el equipo',
            'description' => 'Avisa al equipo de que llegó una propuesta.',
            'trigger' => 'Se recibe una propuesta.',
            'recipient' => 'Integrantes del equipo con permiso para ver propuestas.',
        ],
        'updated' => [
            'name' => 'Propuesta editada',
            'description' => 'Avisa de que el autor modificó su propuesta.',
            'trigger' => 'El autor edita su propuesta.',
            'recipient' => 'El autor y el equipo con permiso para ver propuestas.',
        ],
        'withdrawn' => [
            'name' => 'Propuesta retirada',
            'description' => 'Avisa de que el autor retiró su propuesta.',
            'trigger' => 'El autor retira su propuesta.',
            'recipient' => 'El autor y el equipo con permiso para ver propuestas.',
        ],
    ],
];
