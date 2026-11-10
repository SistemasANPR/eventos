<?php

declare(strict_types=1);

/** Textos del sitio público (React). Llegan al cliente como props compartidas de Inertia. */
return [
    'layout' => [
        'skip_to_content' => 'Saltar al contenido',
        'footer' => 'Plataforma de gestión de eventos',
    ],
    'event' => [
        'when' => 'Fechas y horario',
        'where' => 'Sede',
        'timezone' => 'Zona horaria',
        'language' => 'Idioma',
        'language_option' => 'Ver en :language',
        'no_venue' => 'Sede por confirmar',
        'privacy_link' => 'Aviso de privacidad',
    ],
    'privacy' => [
        'title' => 'Aviso de privacidad',
        'version' => 'Versión :version',
        'published_on' => 'Publicado el :date',
        'back_to_event' => 'Volver al evento',
        'shown_in_base_language' => 'Este aviso se muestra en el idioma base del evento.',
    ],
    'call' => [
        'type' => 'Tipo de propuesta',
        'status' => 'Estado',
        'opens_at' => 'Apertura',
        'closes_at' => 'Cierre',
        'results_at' => 'Resultados',
        'timezone' => 'Zona horaria',
        'tracks' => 'Ejes temáticos',
        'no_tracks' => 'Esta convocatoria aún no tiene ejes temáticos.',
        'back_to_event' => 'Volver al evento',
        'state' => [
            'scheduled' => 'Próximamente',
            'open' => 'Abierta',
            'paused' => 'En pausa',
            'closed' => 'Cerrada',
        ],
        'state_hint' => [
            'scheduled' => 'La convocatoria aún no abre.',
            'open' => 'La convocatoria recibe propuestas.',
            'paused' => 'La recepción de propuestas está en pausa por el momento.',
            'closed' => 'La convocatoria ya no recibe propuestas.',
        ],
    ],
    'languages' => [
        'es' => 'Español',
        'en' => 'English',
        'pt' => 'Português',
    ],
];
