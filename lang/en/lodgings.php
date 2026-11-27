<?php

declare(strict_types=1);

/** Event lodging (plan 030). */
return [
    'navigation' => 'Lodging',
    'empty_heading' => 'No lodging yet',
    'empty_description' => 'Add the hotels or accommodations the event recommends.',
    'create' => 'Add lodging',
    'edit' => 'Edit',
    'delete' => 'Delete',
    'delete_confirm' => 'This lodging will be removed from the event.',
    'saved' => 'Lodging saved.',
    'deleted' => 'Lodging deleted.',
    'failed' => 'The lodging could not be saved.',
    'images' => [
        'too_many' => 'A lodging allows up to :max images.',
    ],
    'columns' => [
        'position' => 'Order',
        'name' => 'Name',
        'address' => 'Address',
        'rate' => 'Rate or code',
        'booking_url' => 'Booking',
        'images' => 'Image',
        'images_count' => 'Images',
    ],
    'fields' => [
        'name' => 'Name',
        'address' => 'Address',
        'description' => 'Description',
        'booking_url' => 'Booking link',
        'booking_url_help' => 'It must start with http:// or https://.',
        'rate' => 'Rate or discount code',
        'images' => 'Images',
        'images_help' => 'JPG, PNG or WebP up to 2 MB each; up to :max images per lodging.',
        'remove_images' => 'Remove current images',
        'position' => 'Order',
        'position_help' => 'If left empty, it is added at the end.',
    ],
];
