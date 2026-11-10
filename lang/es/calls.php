<?php

declare(strict_types=1);

return [
    'title_required' => 'La convocatoria necesita un título en al menos un idioma.',
    'only_draft_can_be_published' => 'Solo se puede publicar una convocatoria en borrador.',
    'privacy_notice_missing' => 'El evento debe tener su aviso de privacidad publicado en todos los idiomas de la convocatoria. Falta en: :locales.',

    'status' => [
        'draft' => 'Borrador',
        'published' => 'Publicada',
        'paused' => 'En pausa',
        'closed' => 'Cerrada',
    ],

    'type' => [
        'session' => 'Sesión',
        'panel' => 'Panel',
        'poster' => 'Póster',
        'workshop' => 'Taller',
    ],
    'relation_title' => 'Convocatorias',
    'new_call' => 'Nueva convocatoria',
    'empty_heading' => 'Este evento aún no tiene convocatorias',
    'empty_description' => 'Crea una convocatoria, completa sus fechas e idiomas y publícala cuando el aviso de privacidad esté publicado.',
    'columns' => [
        'title' => 'Convocatoria',
        'type' => 'Tipo',
        'status' => 'Estado',
        'public_state' => 'Estado público',
        'period' => 'Periodo (hora del evento)',
    ],
    'state' => [
        'scheduled' => 'Programada',
        'open' => 'Abierta',
        'paused' => 'En pausa',
        'closed' => 'Cerrada',
    ],
    'fields' => [
        'submission_type' => 'Tipo de propuesta',
        'locales' => 'Idiomas de la convocatoria',
        'title_es' => 'Título (español)',
        'title_en' => 'Título (inglés)',
        'title_pt' => 'Título (portugués)',
        'description_es' => 'Descripción (español)',
        'description_en' => 'Descripción (inglés)',
        'description_pt' => 'Descripción (portugués)',
        'opens_at' => 'Apertura',
        'closes_at' => 'Cierre',
        'results_at' => 'Resultados',
    ],
    'actions' => [
        'edit' => 'Editar',
        'publish' => 'Publicar',
        'publish_confirm' => '¿Publicar esta convocatoria? Se abrirá y cerrará sola en las fechas indicadas (hora del evento).',
        'published' => 'Convocatoria publicada',
        'saved' => 'Convocatoria guardada',
        'failed' => 'No se pudo completar la acción',
    ],
];
