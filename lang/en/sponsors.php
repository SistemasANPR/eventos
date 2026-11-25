<?php

declare(strict_types=1);

/** Event sponsors (plan 030). */
return [
    'navigation' => 'Sponsors',
    'empty_heading' => 'No sponsors yet',
    'empty_description' => 'Add the organizations that sponsor the event.',
    'create' => 'Add sponsor',
    'edit' => 'Edit',
    'delete' => 'Delete',
    'delete_confirm' => 'This sponsor will be removed from the event, along with its logo.',
    'saved' => 'Sponsor saved.',
    'deleted' => 'Sponsor deleted.',
    'failed' => 'The sponsor could not be saved.',
    'columns' => [
        'position' => 'Order',
        'logo' => 'Logo',
        'name' => 'Name',
        'level' => 'Level',
        'website_url' => 'Website',
    ],
    'fields' => [
        'name' => 'Name',
        'level' => 'Level',
        'level_help' => 'For example: Gold, Silver or Bronze.',
        'website_url' => 'Website',
        'website_url_help' => 'It must start with http:// or https://.',
        'logo' => 'Logo',
        'logo_help' => 'JPG, PNG or WebP up to 2 MB.',
        'remove_logo' => 'Remove the current logo',
        'position' => 'Order',
        'position_help' => 'If left empty, it is added at the end.',
    ],
];
