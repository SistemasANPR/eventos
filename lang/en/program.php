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

    // Program activities (plan 021, F3).
    'activities' => [
        'navigation' => 'Activities',
        'category_invalid' => 'The chosen category does not belong to this event.',
        'empty_heading' => 'There are no activities yet',
        'empty_description' => 'Create the first activity of the event program.',
        'unscheduled' => 'Unscheduled',
        'hidden_by_reversal' => 'Hidden by reversal',
        'timezone_hint' => 'Local time of the event (:timezone).',
        'columns' => [
            'image' => 'Image',
            'title' => 'Name',
            'date' => 'Date',
            'time' => 'Time',
            'categories' => 'Categories',
            'published' => 'Published',
            'origin' => 'Source submission',
        ],
        'filters' => [
            'category' => 'Category',
            'published' => 'Published',
            'published_yes' => 'Published',
            'published_no' => 'Not published',
            'schedule' => 'Scheduling',
            'scheduled' => 'Scheduled',
            'unscheduled' => 'Unscheduled',
        ],
        'form' => [
            'title' => 'Name',
            'starts_on' => 'Start date',
            'ends_on' => 'End date',
            'starts_at' => 'Start time',
            'ends_at' => 'End time',
            'unscheduled_help' => 'This activity has no date or time: saving it with all four schedules it.',
            'icon' => 'Icon',
            'icon_help' => 'JPEG, PNG or WebP, up to 256 KB.',
            'image' => 'Image',
            'image_help' => 'JPEG, PNG or WebP, up to 5 MB.',
            'current_file' => 'Current',
            'remove_icon' => 'Remove the icon',
            'remove_image' => 'Remove the image',
            'summary' => 'Short description',
            'description' => 'Description',
            'categories' => 'Categories',
            'is_published' => 'Published',
        ],
        'actions' => [
            'create' => 'New activity',
            'edit' => 'Edit',
            'publish' => 'Publish',
            'unpublish' => 'Unpublish',
            'delete' => 'Delete',
            'delete_confirm' => 'The activity will be deleted. The assigned speakers are not deleted.',
            'view_origin' => 'View submissions',
        ],
        'messages' => [
            'saved' => 'Activity saved.',
            'published' => 'Activity published.',
            'unpublished' => 'Activity unpublished.',
            'deleted' => 'Activity deleted.',
            'failed' => 'The activity could not be saved.',
        ],
    ],
];
