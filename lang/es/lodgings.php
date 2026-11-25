<?php

declare(strict_types=1);

/** Hospedaje del evento (plan 030). */
return [
    'navigation' => 'Hospedaje',
    'empty_heading' => 'Aún no hay hospedaje',
    'empty_description' => 'Agrega los hoteles o alojamientos que recomienda el evento.',
    'create' => 'Agregar hospedaje',
    'edit' => 'Editar',
    'delete' => 'Eliminar',
    'delete_confirm' => 'Se eliminará este hospedaje del evento.',
    'saved' => 'Hospedaje guardado.',
    'deleted' => 'Hospedaje eliminado.',
    'failed' => 'No se pudo guardar el hospedaje.',
    'columns' => [
        'position' => 'Orden',
        'name' => 'Nombre',
        'address' => 'Dirección',
        'rate' => 'Tarifa o código',
        'booking_url' => 'Reserva',
    ],
    'fields' => [
        'name' => 'Nombre',
        'address' => 'Dirección',
        'description' => 'Descripción',
        'booking_url' => 'Enlace de reserva',
        'booking_url_help' => 'Debe empezar con http:// o https://.',
        'rate' => 'Tarifa o código de descuento',
        'position' => 'Orden',
        'position_help' => 'Si lo dejas vacío, se agrega al final.',
    ],
];
