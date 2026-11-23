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
    ],

    'errors' => [
        'reviewer_not_in_event' => 'Todos los evaluadores deben ser integrantes activos de la organización con rol de evaluador en este evento. No se asignó nada.',
    ],

    'actions' => [
        'assign' => 'Asignar evaluadores',
        'assign_description' => 'Se asignan solo las propuestas marcadas. Las retiradas, ya decididas o invitadas se omiten y el resultado lo indica.',
        'assign_by_scope' => 'Asignar por eje o convocatoria',
        'assign_by_scope_description' => 'Asigna una sola vez las propuestas elegibles del eje o la convocatoria que existen ahora. Las que lleguen después no se asignan solas.',
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
        'notification_failed' => 'No se pudo encolar el aviso a :count evaluador(es); las asignaciones se guardaron.',
        'failed' => 'No se asignó nada',
    ],

    'columns' => [
        'reviewers' => 'Evaluadores',
        'coverage_incomplete' => 'Cobertura incompleta',
    ],
];
