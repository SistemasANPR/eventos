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

    'detail' => [
        'general' => 'Datos generales',
        'answers' => 'Respuestas',
        'participants' => 'Participantes',
        'primary' => 'Principal',
        'retired' => 'campo retirado del formulario',
        'yes' => 'Sí',
        'no' => 'No',
        'no_answers' => 'Esta propuesta no tiene respuestas.',
        'no_participants' => 'Esta propuesta no tiene participantes.',
        'files_attached' => '{0} Sin archivos adjuntos|{1} :count archivo adjunto|[2,*] :count archivos adjuntos',
    ],
];
