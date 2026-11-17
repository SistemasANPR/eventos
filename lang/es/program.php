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

    // --- Ponentes (plan 021, F2) ---
    'speakers' => [
        'navigation' => 'Ponentes',
        'empty_heading' => 'Aún no hay ponentes',
        'empty_description' => 'Da de alta al primero; las personas de una propuesta aceptada llegarán aquí solas.',
        'create' => 'Nuevo ponente',
        'edit' => 'Editar',
        'publish' => 'Publicar',
        'unpublish' => 'Despublicar',
        'delete' => 'Eliminar',
        'delete_confirm' => 'Se eliminará la ficha del ponente. Las actividades en las que participa no se borran.',
        'saved' => 'Ponente guardado.',
        'published' => 'Ponente publicado.',
        'unpublished' => 'Ponente despublicado.',
        'deleted' => 'Ponente eliminado.',
        'failed' => 'No se pudo guardar el ponente.',
        'hidden_by_reversal' => 'Oculto por reversa',
        'columns' => [
            'photo' => 'Foto',
            'first_name' => 'Nombre',
            'last_name' => 'Apellido',
            'company' => 'Organización',
            'job_title' => 'Cargo',
            'categories' => 'Categorías',
            'is_published' => 'Publicado',
        ],
        'filters' => [
            'category' => 'Categoría',
            'published' => 'Publicado',
            'published_yes' => 'Publicados',
            'published_no' => 'No publicados',
        ],
        'fields' => [
            'first_name' => 'Nombre',
            'last_name' => 'Apellido',
            'company' => 'Organización',
            'job_title' => 'Cargo',
            'email' => 'Correo electrónico',
            'country' => 'País',
            'tagline' => 'Descripción de una línea',
            'tagline_help' => 'Hasta 160 caracteres; aparece bajo el nombre.',
            'bio' => 'Biografía',
            'photo' => 'Fotografía',
            'photo_help' => 'JPEG, PNG o WebP de hasta 5 MB.',
            'social_links' => 'Redes',
            'social_network' => 'Red',
            'social_url' => 'Dirección (https)',
            'add_social_link' => 'Agregar red',
            'categories' => 'Categorías',
            'is_published' => 'Publicado',
        ],
        'networks' => [
            'linkedin' => 'LinkedIn',
            'x' => 'X',
            'instagram' => 'Instagram',
            'facebook' => 'Facebook',
            'youtube' => 'YouTube',
            'tiktok' => 'TikTok',
            'website' => 'Sitio web',
        ],
        'errors' => [
            'email_taken' => 'Ya hay un ponente con ese correo en este evento.',
            'social_network_repeated' => 'Cada red puede aparecer una sola vez.',
            'social_url_https' => 'La dirección debe ser una URL https válida.',
            'social_url_domain' => 'La dirección debe ser de :network.',
            'category_invalid' => 'La categoría no existe en este evento.',
        ],
    ],
    // --- Fin de ponentes ---
];
