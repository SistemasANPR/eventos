<?php

declare(strict_types=1);

return [
    'category_name_taken' => 'Ya existe una categoría con ese nombre en este evento.',

    'categories' => [
        'columns' => [
            'name' => 'Nombre',
            'position' => 'Orden',
        ],
        'create' => 'Nueva categoría',
        'edit' => 'Editar',
        'delete' => 'Eliminar',
        'name' => 'Nombre',
        'saved' => 'Categoría guardada.',
        'deleted' => 'Categoría eliminada.',
        'failed' => 'No se pudo guardar la categoría.',

        'speakers' => [
            'navigation' => 'Categorías de ponentes',
            'empty_heading' => 'Aún no hay categorías de ponentes',
            'empty_description' => 'Crea la primera para clasificar a los ponentes del evento.',
            'delete_confirm' => 'Se eliminará la categoría. Los ponentes que la tenían no se borran.',
        ],
        'activities' => [
            'navigation' => 'Categorías de actividades',
            'empty_heading' => 'Aún no hay categorías de actividades',
            'empty_description' => 'Crea la primera para clasificar las actividades del programa.',
            'delete_confirm' => 'Se eliminará la categoría. Las actividades que la tenían no se borran.',
        ],
    ],

    // Actividades del programa (plan 021, F3).
    'activities' => [
        'navigation' => 'Actividades',
        'category_invalid' => 'La categoría elegida no pertenece a este evento.',
        'empty_heading' => 'Aún no hay actividades',
        'empty_description' => 'Crea la primera actividad del programa del evento.',
        'unscheduled' => 'Sin programar',
        'hidden_by_reversal' => 'Oculto por reversa',
        'timezone_hint' => 'Hora local del evento (:timezone).',
        'columns' => [
            'image' => 'Imagen',
            'title' => 'Nombre',
            'date' => 'Fecha',
            'time' => 'Hora',
            'categories' => 'Categorías',
            'published' => 'Publicado',
            'origin' => 'Propuesta de origen',
        ],
        'filters' => [
            'category' => 'Categoría',
            'published' => 'Publicado',
            'published_yes' => 'Publicadas',
            'published_no' => 'No publicadas',
            'schedule' => 'Programación',
            'scheduled' => 'Programadas',
            'unscheduled' => 'Sin programar',
        ],
        'form' => [
            'title' => 'Nombre',
            'starts_on' => 'Fecha de inicio',
            'ends_on' => 'Fecha de fin',
            'starts_at' => 'Hora de inicio',
            'ends_at' => 'Hora de fin',
            'unscheduled_help' => 'Esta actividad no tiene fecha ni hora: al guardarla con las cuatro quedará programada.',
            'icon' => 'Icono',
            'icon_help' => 'JPEG, PNG o WebP, hasta 256 KB.',
            'image' => 'Imagen',
            'image_help' => 'JPEG, PNG o WebP, hasta 5 MB.',
            'current_file' => 'Actual',
            'remove_icon' => 'Quitar el icono',
            'remove_image' => 'Quitar la imagen',
            'summary' => 'Descripción corta',
            'description' => 'Descripción',
            'categories' => 'Categorías',
            'is_published' => 'Publicado',
        ],
        'actions' => [
            'create' => 'Nueva actividad',
            'edit' => 'Editar',
            'publish' => 'Publicar',
            'unpublish' => 'Despublicar',
            'delete' => 'Eliminar',
            'delete_confirm' => 'Se eliminará la actividad. Los ponentes asignados no se borran.',
            'view_origin' => 'Ver propuestas',
        ],
        'messages' => [
            'saved' => 'Actividad guardada.',
            'published' => 'Actividad publicada.',
            'unpublished' => 'Actividad despublicada.',
            'deleted' => 'Actividad eliminada.',
            'failed' => 'No se pudo guardar la actividad.',
        ],
    ],
];
