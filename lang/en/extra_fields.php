<?php

declare(strict_types=1);

/** Extra details of a lodging or a sponsor (plan 030, DA-7). */
return [
    'section' => 'Additional details',
    'help' => 'Add any details you need (for example "Phone" or "Contact") as text, or a photo. Up to :max per record.',
    'add' => 'Add detail',
    'label' => 'Detail title',
    'type' => 'Type',
    'type_text' => 'Text',
    'type_image' => 'Photo',
    'value' => 'Text',
    'image' => 'Photo',
    'image_help' => 'JPG, PNG or WebP, up to 2 MB.',
    'image_current' => 'It already has a saved photo. Upload another only to replace it.',
    'untitled' => 'Untitled detail',
    'errors' => [
        'value_required' => 'The detail ":label" needs a text.',
        'image_required' => 'The detail ":label" needs a photo.',
    ],
];
