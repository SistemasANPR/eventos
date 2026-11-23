<?php

declare(strict_types=1);

return [
    'profile' => [
        'title' => 'Datos de la organización',
        'name' => 'Nombre',
        'slug' => 'Identificador',
        'slug_help' => 'Es la parte de la dirección web que identifica a la organización: de 3 a 63 caracteres, en minúsculas, números y guiones.',
        'slug_warning' => 'Cambiar el identificador cambia las direcciones web de la organización.',
        'slug_warning_detail' => 'Las direcciones antiguas dejarán de funcionar y no se redirigen: los enlaces ya compartidos, impresos o guardados darán error 404.',
    ],
    'slug' => [
        'invalid' => 'El identificador debe tener de 3 a 63 caracteres, en minúsculas, números y guiones simples (sin acentos ni espacios).',
        'reserved' => 'Ese identificador está reservado. Elige otro.',
    ],
];
