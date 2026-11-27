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
    'images' => [
        'too_many' => 'Un hospedaje admite hasta :max imágenes.',
    ],
    'columns' => [
        'position' => 'Orden',
        'name' => 'Nombre',
        'address' => 'Dirección',
        'rate' => 'Tarifa o código',
        'booking_url' => 'Reserva',
        'images' => 'Imagen',
        'images_count' => 'Imágenes',
    ],
    'fields' => [
        'name' => 'Nombre',
        'address' => 'Dirección',
        'description' => 'Descripción',
        'booking_url' => 'Enlace de reserva',
        'booking_url_help' => 'Debe empezar con http:// o https://.',
        'rate' => 'Tarifa o código de descuento',
        'images' => 'Imágenes',
        'images_help' => 'JPG, PNG o WebP de hasta 2 MB cada una; hasta :max imágenes por hospedaje.',
        'remove_images' => 'Quitar imágenes actuales',
        'position' => 'Orden',
        'position_help' => 'Si lo dejas vacío, se agrega al final.',
    ],
];
