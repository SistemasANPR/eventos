<?php

declare(strict_types=1);

/** Datos adicionales de un hospedaje o de un patrocinador (plan 030, DA-7). */
return [
    'section' => 'Datos adicionales',
    'help' => 'Agrega los datos que necesites (por ejemplo «Teléfono» o «Contacto») como texto, o una foto. Hasta :max por registro.',
    'add' => 'Agregar dato',
    'label' => 'Título del dato',
    'type' => 'Tipo',
    'type_text' => 'Texto',
    'type_image' => 'Foto',
    'value' => 'Texto',
    'image' => 'Foto',
    'image_help' => 'JPG, PNG o WebP de hasta 2 MB.',
    'image_current' => 'Ya tiene una foto guardada. Sube otra solo si quieres reemplazarla.',
    'untitled' => 'Dato sin título',
    'errors' => [
        'value_required' => 'El dato «:label» necesita un texto.',
        'image_required' => 'El dato «:label» necesita una foto.',
    ],
];
