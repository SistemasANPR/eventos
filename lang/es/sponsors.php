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
        'level_help' => 'Por ejemplo: Oro, Plata o Bronce.',
        'website_url' => 'Sitio web',
        'website_url_help' => 'Debe empezar con http:// o https://.',
        'logo' => 'Logotipo',
        'logo_help' => 'JPG, PNG o WebP de hasta 2 MB.',
        'remove_logo' => 'Quitar el logotipo actual',
        'position' => 'Orden',
        'position_help' => 'Si lo dejas vacío, se agrega al final.',
    ],
];
