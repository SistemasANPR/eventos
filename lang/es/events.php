<?php

declare(strict_types=1);

return [
    'name_required' => 'El evento necesita un nombre en al menos un idioma.',
    'name_required_in_default_locale' => 'Para publicar, el evento necesita su nombre en el idioma por defecto (:locale).',
    'only_draft_can_be_published' => 'Solo se puede publicar un evento en borrador.',
    'only_published_can_be_archived' => 'Solo se puede archivar un evento publicado.',
    'slug_change_needs_confirmation' => 'Este evento ya está publicado: cambiar su identificador rompe los enlaces que ya compartiste. Confirma el cambio para continuar.',

    'navigation' => 'Eventos',
    'model' => 'evento',
    'plural' => 'eventos',

    'sections' => [
        'general' => 'Información general',
        'dates' => 'Fechas',
        'venue' => 'Sede',
        'languages' => 'Idiomas y moneda',
    ],

    'fields' => [
        'name_es' => 'Nombre (español)',
        'name_en' => 'Nombre (inglés)',
        'name_pt' => 'Nombre (portugués)',
        'description_es' => 'Descripción (español)',
        'description_en' => 'Descripción (inglés)',
        'description_pt' => 'Descripción (portugués)',
        'slug' => 'Identificador en la URL',
        'slug_help' => 'Letras minúsculas, números y guiones. Forma el enlace público del evento.',
        'type' => 'Tipo de evento',
        'currency' => 'Moneda',
        'starts_at' => 'Inicio',
        'ends_at' => 'Fin',
        'dates_help' => 'Hora local de la zona horaria del evento.',
        'timezone' => 'Zona horaria',
        'venue_name' => 'Nombre de la sede',
        'venue_address' => 'Dirección',
        'city' => 'Ciudad',
        'locales' => 'Idiomas del evento',
        'default_locale' => 'Idioma por defecto',
        'confirm_slug_change' => 'Confirmo cambiar el identificador',
        'confirm_slug_change_help' => 'El evento ya está publicado: cambiarlo rompe los enlaces que ya compartiste.',
        'public_url' => 'Enlace público',
        'status' => 'Estado',
    ],

    'table' => [
        'empty_heading' => 'Todavía no hay eventos',
        'empty_description' => 'Crea tu primer evento para configurar su convocatoria. Aquí elegirás con qué evento trabajar.',
        'name' => 'Evento',
        'starts_at' => 'Fechas',
    ],

    'status' => [
        'draft' => 'Borrador',
        'published' => 'Publicado',
        'archived' => 'Archivado',
    ],

    'type' => [
        'congress' => 'Congreso',
        'forum' => 'Foro',
        'workshop' => 'Taller',
        'webinar' => 'Seminario web',
        'expo' => 'Exposición',
    ],

    'actions' => [
        'publish' => 'Publicar',
        'publish_confirm' => '¿Publicar este evento? Será visible para cualquier persona con su enlace.',
        'archive' => 'Archivar',
        'archive_confirm' => '¿Archivar este evento? Dejará de ser visible al público; sus datos se conservan.',
        'published' => 'Evento publicado',
        'archived' => 'Evento archivado',
        'failed' => 'No se pudo completar la acción',
    ],
];
