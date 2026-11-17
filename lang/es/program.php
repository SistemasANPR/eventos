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
];
