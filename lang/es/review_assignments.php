<?php

declare(strict_types=1);

/* Asignación de evaluadores a propuestas (plan 011, F3a). */
return [
    'status' => [
        'pending' => 'Pendiente',
        'in_progress' => 'En progreso',
        'submitted' => 'Enviada',
        'declined_conflict' => 'Conflicto declarado',
        'cancelled' => 'Cancelada',
    ],

    'skip_reasons' => [
        'not_from_call' => 'Propuesta invitada: no se evalúa',
        'ineligible_status' => 'Propuesta retirada o ya decidida',
        'conflict_of_interest' => 'Conflicto de interés: el evaluador comparte correo con un participante',
        'already_assigned' => 'Ya estaba asignada',
        'previously_cancelled' => 'Asignación cancelada antes con evaluación conservada',
        'reviewer_unavailable' => 'El evaluador de la regla ya no es integrante activo ni evaluador de este evento',
    ],

    'errors' => [
        'reviewer_not_in_event' => 'Todos los evaluadores deben ser integrantes activos de la organización con rol de evaluador en este evento. No se asignó nada.',
    ],

    'actions' => [
        'assign' => 'Asignar evaluadores',
        'assign_description' => 'Se asignan solo las propuestas marcadas. Las retiradas, ya decididas o invitadas se omiten y el resultado lo indica.',
        'assign_by_scope' => 'Asignar por eje o convocatoria',
        'assign_by_scope_description' => 'Asigna las propuestas elegibles del eje o la convocatoria que existen ahora y guarda una regla: las que lleguen después se asignan solas a estos evaluadores, sin correo. Quitar la regla no borra asignaciones.',
        'sync' => 'Asignar propuestas nuevas',
        'sync_description' => 'Aplica ahora las reglas de alcance: asigna a cada evaluador las propuestas de su eje o convocatoria que aún no tiene. No envía correo.',
        'remove' => 'Quitar evaluador',
        'remove_description' => 'Una asignación pendiente se elimina; si el evaluador ya empezó o envió, se cancela y su evaluación se conserva sin contar en los resultados.',
    ],

    'form' => [
        'scope' => 'Alcance',
        'scope_track' => 'Eje temático',
        'scope_call' => 'Convocatoria',
        'track' => 'Eje',
        'call' => 'Convocatoria',
        'reviewers' => 'Evaluadores',
        'no_reviewers' => 'Este evento aún no tiene evaluadores asignados.',
        'assignment' => 'Evaluador asignado',
        'not_in_event' => 'sin asignación a este evento',
    ],

    'removal' => [
        'deleted' => 'Asignación eliminada.',
        'cancelled' => 'Asignación cancelada; su evaluación se conserva.',
    ],

    'report' => [
        'title' => 'Asignación de evaluadores',
        'assigned' => 'Asignaciones creadas: :count.',
        'skipped' => 'Omitidas: :count.',
        'more' => '… y :count más.',
        'rules_created' => 'Reglas de alcance guardadas: :count. Las propuestas nuevas del alcance se asignarán solas.',
        'notification_failed' => 'No se pudo encolar el aviso a :count evaluador(es); las asignaciones se guardaron.',
        'failed' => 'No se asignó nada',
    ],

    'columns' => [
        'reviewers' => 'Evaluadores',
        'coverage_incomplete' => 'Cobertura incompleta',
    ],

    'invite' => [
        'action' => 'Invitar evaluador',
        'description' => 'Da de alta a la persona como evaluadora de este evento y le envía un correo para entrar. Si ya es integrante de la organización, solo se le asigna el evento y no se le envía ningún enlace.',
        'submit' => 'Invitar',
        'name' => 'Nombre',
        'email' => 'Correo electrónico',
        'sent' => 'Invitación enviada.',
        'already_member' => 'Esa persona ya es integrante de la organización: se le asignó este evento y no se envió ningún enlace.',
        'needed_title' => 'No se asignó nada',
        'needed_body' => 'Aún no tienen asignación a este evento: :names. Invítalos desde «Evaluadores» y vuelve a asignar.',
        'go_to_reviewers' => 'Ir a Evaluadores',
    ],

    'reviewers' => [
        'navigation' => 'Evaluadores',
        'heading' => 'Evaluadores del evento',
        'columns' => [
            'name' => 'Evaluador',
            'email' => 'Correo',
            'assigned' => 'Propuestas asignadas',
            'progress' => 'Avance',
        ],
        'no_assignments' => 'Sin propuestas asignadas',
        'progress' => ':done de :total evaluadas',
        'coverage_ok' => 'Todas las propuestas por evaluar tienen al menos el mínimo de evaluadores.',
        'coverage_item' => ':scope «:name»: :incomplete de :total propuestas por evaluar con menos evaluadores que el mínimo.',
        'empty_heading' => 'Este evento aún no tiene evaluadores',
        'empty_description' => 'Invita a un evaluador para poder asignarle propuestas.',
    ],

    'rules' => [
        'navigation' => 'Reglas de asignación',
        'back' => 'Volver a Evaluadores',
        'columns' => [
            'reviewer' => 'Evaluador',
            'type' => 'Tipo',
            'scope' => 'Alcance',
            'created_at' => 'Creada',
        ],
        'remove' => 'Quitar regla',
        'remove_description' => 'Las propuestas ya asignadas se conservan; las que lleguen después dejarán de asignarse a este evaluador por esta regla.',
        'removed' => 'Regla quitada. Las asignaciones ya hechas se conservan.',
        'empty_heading' => 'No hay reglas de asignación',
        'empty_description' => 'Se crean al asignar evaluadores por eje o convocatoria.',
    ],
];
