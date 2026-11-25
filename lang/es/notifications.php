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
        'two_factor_enabled' => [
            'name' => 'Verificación en dos pasos activada',
            'description' => 'Aviso de seguridad con la fecha; nunca lleva el secreto ni los códigos de recuperación.',
            'trigger' => 'Alguien confirma la verificación en dos pasos de su cuenta.',
            'recipient' => 'El titular de la cuenta.',
        ],
        'two_factor_reset' => [
            'name' => 'Verificación en dos pasos reiniciada',
            'description' => 'Aviso de seguridad de que otro administrador reinició la verificación, con la fecha.',
            'trigger' => 'Un administrador o la plataforma reinicia la verificación en dos pasos de una cuenta.',
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
            'recipient' => 'Integrantes del equipo con permiso para ver propuestas.',
        ],
        'withdrawn_author' => [
            'name' => 'Retiro de la propuesta confirmado al autor',
            'description' => 'Confirma al autor que su propuesta quedó retirada.',
            'trigger' => 'El autor retira su propuesta.',
            'recipient' => 'La persona que envió la propuesta.',
        ],
        'accepted' => [
            'name' => 'Propuesta aceptada',
            'description' => 'Avisa al autor que su propuesta fue aceptada, con el comentario opcional del equipo. No lleva enlace de acceso: invita a pedir uno nuevo. No se puede desactivar.',
            'trigger' => 'Un usuario con permiso publica los resultados de la convocatoria (o decide una propuesta cuando los resultados ya están publicados).',
            'recipient' => 'La persona que envió la propuesta.',
        ],
        'rejected' => [
            'name' => 'Propuesta no seleccionada',
            'description' => 'Avisa al autor que su propuesta no fue seleccionada, con el comentario opcional del equipo. No lleva enlace de acceso. No se puede desactivar.',
            'trigger' => 'Un usuario con permiso publica los resultados de la convocatoria (o decide una propuesta cuando los resultados ya están publicados).',
            'recipient' => 'La persona que envió la propuesta.',
        ],
        'waitlisted' => [
            'name' => 'Propuesta en lista de espera',
            'description' => 'Avisa al autor que su propuesta quedó en lista de espera, con el comentario opcional del equipo. No lleva enlace de acceso. No se puede desactivar.',
            'trigger' => 'Un usuario con permiso publica los resultados de la convocatoria (o decide una propuesta cuando los resultados ya están publicados).',
            'recipient' => 'La persona que envió la propuesta.',
        ],
    ],
    'call' => [
        'closing_reminder' => [
            'name' => 'Recordatorio de cierre de la convocatoria',
            'description' => 'Recuerda a los autores con una propuesta enviada que la convocatoria está por cerrar. Un solo recordatorio por autor y convocatoria; si la edición por el autor está disponible, lo invita a corregir su propuesta.',
            'trigger' => 'Tarea diaria: faltan los días configurados (por defecto 3) para el cierre de la convocatoria, en la zona horaria del evento.',
            'recipient' => 'La persona que envió cada propuesta pendiente (una sola vez por convocatoria).',
        ],
    ],
    'reviewer' => [
        'assignment' => [
            'name' => 'Propuestas asignadas al evaluador',
            'description' => 'Un solo resumen por evaluador y por operación de asignación, con la cantidad de propuestas nuevas y el enlace a su tablero; una propuesta asignada después por una regla de alcance no envía correo.',
            'trigger' => 'Un integrante del equipo asigna propuestas a un evaluador.',
            'recipient' => 'El evaluador asignado.',
        ],
        'invitation' => [
            'name' => 'Invitación al evaluador',
            'description' => 'Invita a una persona a evaluar propuestas de un evento. Si es una cuenta nueva, el enlace permite establecer su contraseña y caduca en el plazo de los demás enlaces de contraseña.',
            'trigger' => 'Un integrante del equipo invita a un evaluador a un evento.',
            'recipient' => 'La persona invitada como evaluadora.',
        ],
    ],
];
