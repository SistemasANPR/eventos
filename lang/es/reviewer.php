<?php

declare(strict_types=1);

/** Panel del evaluador (plan 011, F4). */
return [
    'brand' => 'Evaluación',
    'period_closed_error' => 'El periodo de evaluación de este evento está cerrado: ya no se pueden crear ni cambiar evaluaciones.',
    'no_published_rubric' => 'Este tipo de propuesta aún no tiene una rúbrica publicada, así que no se puede evaluar.',
    'draft_after_submit' => 'La evaluación ya se envió: para cambiarla, reevalúa y envíala de nuevo.',
    'unknown_criterion' => 'Hay una respuesta que no corresponde a ningún criterio de la rúbrica.',

    'dashboard' => [
        'navigation' => 'Inicio',
        'title' => 'Mis evaluaciones',
        'summary' => 'Resumen de mis propuestas asignadas',
        'cards' => [
            'assigned' => 'Asignadas',
            'pending' => 'Pendientes',
            'in_progress' => 'En progreso',
            'submitted' => 'Enviadas',
            'declined_conflict' => 'Con conflicto',
        ],
        'periods' => 'Periodo de evaluación',
        'period_open' => 'Abierto',
        'period_closed' => 'Cerrado',
        'period_closed_notice' => 'el periodo de evaluación está cerrado y ya no se puede calificar.',
        'no_periods' => 'Aún no tienes un evento asignado como evaluador.',
        'empty_heading' => 'Aún no tienes propuestas asignadas',
        'empty_description' => 'Cuando el equipo organizador te asigne propuestas, las verás aquí y recibirás un aviso por correo.',
        'go_to_list' => 'Ver mis propuestas',
    ],

    'list' => [
        'navigation' => 'Propuestas asignadas',
        'model' => 'propuesta asignada',
        'plural' => 'propuestas asignadas',
        'period_closed_notice' => 'El periodo de evaluación está cerrado en: :events. Ya no se puede calificar.',
        'empty_heading' => 'No hay propuestas asignadas',
        'empty_description' => 'No tienes propuestas asignadas con estos filtros.',
        'columns' => [
            'title' => 'Título',
            'event' => 'Evento',
            'track' => 'Eje',
            'call' => 'Convocatoria',
            'status' => 'Estado',
        ],
        'actions' => [
            'review' => 'Calificar',
            'continue' => 'Continuar',
            'review_again' => 'Reevaluar',
        ],
    ],

    'evaluate' => [
        'breadcrumb' => 'Evaluar',
        'heading' => 'Evaluación',
        'description' => 'Califica cada criterio. Puedes guardar un borrador y terminar después; mientras el periodo esté abierto puedes reevaluar.',
        'save_draft' => 'Guardar borrador',
        'submit' => 'Enviar evaluación',
        'resubmit' => 'Enviar de nuevo',
        'draft_saved' => 'Borrador guardado',
        'submitted' => 'Evaluación enviada',
        'scale_hint' => 'Obligatorio: un número de :min a :max.',
        'cannot' => [
            'conflict' => 'Esta propuesta no se puede calificar: declaraste un conflicto de interés o la asignación ya no está vigente.',
            'period_closed' => 'El periodo de evaluación de este evento está cerrado: puedes consultar la propuesta, pero ya no calificarla.',
            'no_rubric' => 'Este tipo de propuesta aún no tiene una rúbrica publicada, así que todavía no se puede calificar.',
        ],
    ],
];
