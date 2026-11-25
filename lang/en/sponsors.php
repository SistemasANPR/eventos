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
    'category_invalid' => 'The chosen level does not belong to this event.',
    'categories' => [
        'button' => 'Categories',
        'back' => 'Sponsors',
        'navigation' => 'Sponsor categories',
        'create' => 'New category',
        'edit' => 'Edit',
        'delete' => 'Delete',
        'delete_confirm' => 'This category will be deleted; the sponsors that have it will be left without a level.',
        'name' => 'Name',
        'columns' => [
            'name' => 'Name',
            'position' => 'Order',
        ],
        'empty_heading' => 'There are no sponsor categories yet',
        'empty_description' => 'Create the first one (for example Gold, Silver or Bronze) to classify the sponsors of the event.',
        'saved' => 'Category saved.',
        'deleted' => 'Category deleted.',
        'failed' => 'The category could not be saved.',
        'name_taken' => 'A category with that name already exists in this event.',
    ],
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
        'level_help' => 'Choose a category; they are created from the Categories button.',
        'level_none' => 'No level',
        'website_url' => 'Website',
        'website_url_help' => 'It must start with http:// or https://.',
        'logo' => 'Logo',
        'logo_help' => 'JPG, PNG or WebP up to 2 MB.',
        'remove_logo' => 'Remove the current logo',
        'position' => 'Order',
        'position_help' => 'If left empty, it is added at the end.',
    ],
];
