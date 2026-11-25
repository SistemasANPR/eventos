<?php

declare(strict_types=1);

return [
    'brand' => 'Plataforma',

    'status' => ['active' => 'Activa', 'suspended' => 'Suspendida'],

    'yes' => 'Sí',
    'no' => 'No',

    'users' => [
        'label' => 'Usuario',
        'plural' => 'Usuarios',
        'empty' => 'Aún no hay usuarios',
        'memberships' => 'Membresías',
        'columns' => [
            'name' => 'Nombre',
            'email' => 'Correo',
            'platform' => 'De plataforma',
            'organizations' => 'Organizaciones activas',
        ],
        'reset_two_factor' => [
            'action' => 'Reiniciar verificación en dos pasos',
            'help' => 'Solo cuando no queda otro administrador que pueda hacerlo. Confirma la identidad de la persona por un canal distinto al correo de la cuenta y regístralo: queda auditado y la persona recibe un aviso por correo.',
            'channel' => 'Canal de la verificación',
            'channels' => [
                'phone_call' => 'Llamada a un contacto registrado',
                'message' => 'Mensaje a un contacto registrado',
                'in_person' => 'En persona',
            ],
            'note' => 'Nota de la verificación',
            'note_help' => 'Quién confirmó la identidad y a qué contacto previamente registrado (sin contraseñas ni códigos).',
            'done' => 'Verificación en dos pasos reiniciada.',
        ],
    ],

    'events' => [
        'label' => 'Evento',
        'plural' => 'Eventos',
        'empty' => 'Aún no hay eventos',
        'columns' => [
            'organization' => 'Organización',
            'name' => 'Evento',
            'status' => 'Estado',
            'dates' => 'Fechas (hora del evento)',
            'locales' => 'Idiomas',
        ],
    ],

    'audit' => [
        'label' => 'Registro de auditoría',
        'plural' => 'Auditoría',
        'empty' => 'No hay registros de auditoría',
        'columns' => [
            'date' => 'Fecha',
            'event' => 'Evento',
            'actor' => 'Actor',
            'organization' => 'Organización',
            'subject' => 'Objeto',
            'changes' => 'Cambios',
        ],
        'filters' => [
            'date' => 'Fecha',
            'from' => 'Desde',
            'until' => 'Hasta',
        ],
    ],

    'organizations' => [
        'label' => 'Organización',
        'plural' => 'Organizaciones',
        'empty' => 'Aún no hay organizaciones',
        'admins' => 'Administradores',
        'recent_audit' => 'Auditoría reciente',
        'columns' => [
            'name' => 'Nombre',
            'slug' => 'Identificador',
            'status' => 'Estado',
            'members' => 'Miembros activos',
            'events' => 'Eventos',
        ],
        'fields' => [
            'name' => 'Nombre de la organización',
            'name_help' => 'El identificador de la URL se genera a partir del nombre y no se puede cambiar después.',
            'admin_name' => 'Nombre del primer administrador',
            'admin_email' => 'Correo del primer administrador',
            'admin_email_help' => 'Recibirá un correo para fijar su contraseña.',
            'reason' => 'Motivo de la suspensión',
        ],
        'create' => 'Nueva organización',
        'created' => 'Organización creada',
        'already_exists' => 'Ya existe una organización con ese identificador',
        'suspend' => 'Suspender',
        'suspend_help' => 'Sus miembros perderán el acceso al panel y el sitio público dejará de responder. Los datos no se tocan.',
        'suspended' => 'Organización suspendida',
        'reactivate' => 'Reactivar',
        'reactivate_help' => 'Sus miembros recuperarán el acceso y el sitio público volverá a responder.',
        'reactivated' => 'Organización reactivada',
    ],
];
