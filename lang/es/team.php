<?php

declare(strict_types=1);

return [
    'set_password' => [
        'subject' => 'Define tu contraseña',
        'greeting' => 'Hola',
        'line' => 'Te dieron acceso a la plataforma. Usa el botón para definir tu contraseña; el enlace caduca en 60 minutos.',
        'action' => 'Definir contraseña',
    ],
    'membership_added' => [
        'subject' => 'Ahora formas parte de :organization',
        'greeting' => 'Hola',
        'line' => 'La organización :organization te dio acceso a su equipo. Inicia sesión con tu cuenta de siempre para verla.',
        'action' => 'Iniciar sesión',
    ],
    'roles' => [
        'org_admin' => 'Administrador',
        'organizer' => 'Organizador',
        'reviewer' => 'Evaluador',
    ],
    'status' => [
        'active' => 'Activo',
        'deactivated' => 'Desactivado',
    ],
    'resource' => [
        'label' => 'Integrante',
        'plural' => 'Equipo',
        'name' => 'Nombre',
        'email' => 'Correo',
        'role' => 'Rol',
        'status' => 'Estado',
    ],
    'invite' => [
        'action' => 'Dar de alta',
        'submit' => 'Dar de alta',
        'sent' => 'Alta registrada. Si el correo es nuevo recibirá un enlace para definir su contraseña.',
        'throttled' => 'Demasiadas altas en poco tiempo. Espera un minuto e inténtalo de nuevo.',
        'already_member' => 'Ese correo ya pertenece al equipo de esta organización.',
    ],
    'resend' => [
        'action' => 'Reenviar invitación',
        'confirm' => 'Se enviará de nuevo el correo para definir la contraseña, con un enlace nuevo que caduca en 60 minutos.',
        'done' => 'Invitación reenviada. La persona recibirá un correo para definir su contraseña.',
    ],
    'change_role' => [
        'action' => 'Cambiar rol',
        'done' => 'Rol actualizado.',
    ],
    'deactivate' => [
        'action' => 'Desactivar',
        'confirm' => 'La persona perderá el acceso a esta organización. Su historial se conserva.',
        'done' => 'Integrante desactivado.',
    ],
    'errors' => [
        'last_admin' => 'La organización debe conservar al menos un administrador activo.',
        'not_pending' => 'Esa persona ya inició sesión: puede usar «¿Olvidaste tu contraseña?» si la perdió.',
        'not_active' => 'La persona ya no es integrante activo de esta organización.',
    ],
];
