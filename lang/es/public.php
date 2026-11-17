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
    // Catálogo interno de componentes (`/components`): herramienta del equipo, datos ficticios.
    'catalog' => [
        'title' => 'Catálogo de componentes',
        'intro' => 'Inventario de los componentes propios del sitio público, con su demo. Herramienta interna: no se indexa ni se enlaza desde ningún menú.',
        'level_label' => 'Nivel',
        'category_label' => 'Categoría',
        'use_when' => 'Usar cuando',
        'avoid_for' => 'No usar para',
        'demo' => 'Demo',
        'no_demo' => 'Sin demo.',
        'level' => [
            'atom' => 'Átomo',
            'molecule' => 'Molécula',
            'organism' => 'Organismo',
            'template' => 'Plantilla',
            'infrastructure' => 'Infraestructura',
        ],
        'demo_data' => [
            'rich_text' => '<p>Tratamos tus datos con <strong>cuidado</strong> y <em>transparencia</em>.</p><ul><li>Primer punto</li><li>Segundo punto con un <a href="https://example.test">enlace</a></li></ul>',
            'link' => 'Un enlace de texto',
            'link_active' => 'Opción activa',
            'fact_label' => 'Sede',
            'fact_value' => 'Centro de Convenciones, Ciudad de México',
            'skip_hint' => 'Pulsa la tecla Tab para ver el enlace.',
            'event_name' => 'Congreso de Parques Urbanos',
            'event_description' => 'Tres días para compartir experiencias sobre el cuidado y la gestión de los parques.',
            'event_venue' => 'Centro de Convenciones',
            'event_city' => 'Ciudad de México',
        ],
    ],
    'languages' => [
        'es' => 'Español',
        'en' => 'English',
        'pt' => 'Português',
    ],
];
