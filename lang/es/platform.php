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
            'slug' => 'Identificador en la URL',
            'slug_help' => 'Letras minúsculas, números y guiones. No se puede cambiar después.',
            'name' => 'Nombre de la organización',
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
