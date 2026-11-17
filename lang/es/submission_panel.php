<?php

declare(strict_types=1);

return [
    'navigation' => 'Propuestas',
    'model' => 'propuesta',
    'plural' => 'propuestas',
    'empty' => 'Todavía no hay propuestas en este evento',

    'columns' => [
        'title' => 'Título',
        'call' => 'Convocatoria',
        'type' => 'Tipo',
        'track' => 'Eje',
        'status' => 'Estado',
        'submitted_at' => 'Enviada',
    ],

    'filters' => [
        'range' => 'Fecha de envío',
        'from' => 'Desde',
        'until' => 'Hasta',
        'invalid' => 'El filtro no es válido. Revisa los valores e inténtalo de nuevo.',
    ],
];
