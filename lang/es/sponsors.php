<?php

declare(strict_types=1);

/** Patrocinadores del evento (plan 030). */
return [
    'navigation' => 'Patrocinadores',
    'empty_heading' => 'Aún no hay patrocinadores',
    'empty_description' => 'Agrega a las organizaciones que patrocinan el evento.',
    'create' => 'Agregar patrocinador',
    'edit' => 'Editar',
    'delete' => 'Eliminar',
    'delete_confirm' => 'Se eliminará este patrocinador del evento, junto con su logotipo.',
    'saved' => 'Patrocinador guardado.',
    'deleted' => 'Patrocinador eliminado.',
    'failed' => 'No se pudo guardar el patrocinador.',
    'category_invalid' => 'El nivel elegido no pertenece a este evento.',
    'categories' => [
        'button' => 'Categorías',
        'back' => 'Patrocinadores',
        'navigation' => 'Categorías de patrocinadores',
        'create' => 'Nueva categoría',
        'edit' => 'Editar',
        'delete' => 'Eliminar',
        'delete_confirm' => 'Se eliminará esta categoría; los patrocinadores que la tengan quedarán sin nivel.',
        'name' => 'Nombre',
        'columns' => [
            'name' => 'Nombre',
            'position' => 'Orden',
        ],
        'empty_heading' => 'Aún no hay categorías de patrocinadores',
        'empty_description' => 'Crea la primera (por ejemplo Oro, Plata o Bronce) para clasificar a los patrocinadores del evento.',
        'saved' => 'Categoría guardada.',
        'deleted' => 'Categoría eliminada.',
        'failed' => 'No se pudo guardar la categoría.',
        'name_taken' => 'Ya existe una categoría con ese nombre en este evento.',
    ],
    'columns' => [
        'position' => 'Orden',
        'logo' => 'Logotipo',
        'name' => 'Nombre',
        'level' => 'Nivel',
        'website_url' => 'Sitio web',
    ],
    'fields' => [
        'name' => 'Nombre',
        'level' => 'Nivel',
        'level_help' => 'Elige una categoría; se crean desde el botón Categorías.',
        'level_none' => 'Sin nivel',
        'website_url' => 'Sitio web',
        'website_url_help' => 'Debe empezar con http:// o https://.',
        'logo' => 'Logotipo',
        'logo_help' => 'JPG, PNG o WebP de hasta 2 MB.',
        'remove_logo' => 'Quitar el logotipo actual',
        'position' => 'Orden',
        'position_help' => 'Si lo dejas vacío, se agrega al final.',
    ],
];
