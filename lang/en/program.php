<?php

declare(strict_types=1);

return [
    'category_name_taken' => 'A category with that name already exists in this event.',

    'categories' => [
        'columns' => [
            'name' => 'Name',
            'position' => 'Order',
        ],
        'create' => 'New category',
        'edit' => 'Edit',
        'delete' => 'Delete',
        'name' => 'Name',
        'saved' => 'Category saved.',
        'deleted' => 'Category deleted.',
        'failed' => 'The category could not be saved.',

        'speakers' => [
            'navigation' => 'Speaker categories',
            'empty_heading' => 'There are no speaker categories yet',
            'empty_description' => 'Create the first one to classify the speakers of the event.',
            'delete_confirm' => 'The category will be deleted. The speakers that had it are not deleted.',
        ],
        'activities' => [
            'navigation' => 'Activity categories',
            'empty_heading' => 'There are no activity categories yet',
            'empty_description' => 'Create the first one to classify the activities of the program.',
            'delete_confirm' => 'The category will be deleted. The activities that had it are not deleted.',
        ],
    ],
];
