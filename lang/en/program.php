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

    // --- Speakers (plan 021, F2) ---
    'speakers' => [
        'navigation' => 'Speakers',
        'empty_heading' => 'There are no speakers yet',
        'empty_description' => 'Add the first one; the people of an accepted submission will arrive here on their own.',
        'create' => 'New speaker',
        'edit' => 'Edit',
        'publish' => 'Publish',
        'unpublish' => 'Unpublish',
        'delete' => 'Delete',
        'delete_confirm' => 'The speaker profile will be deleted. The activities they take part in are not deleted.',
        'saved' => 'Speaker saved.',
        'published' => 'Speaker published.',
        'unpublished' => 'Speaker unpublished.',
        'deleted' => 'Speaker deleted.',
        'failed' => 'The speaker could not be saved.',
        'hidden_by_reversal' => 'Hidden by reversal',
        'columns' => [
            'photo' => 'Photo',
            'first_name' => 'First name',
            'last_name' => 'Last name',
            'company' => 'Organization',
            'job_title' => 'Job title',
            'categories' => 'Categories',
            'is_published' => 'Published',
        ],
        'filters' => [
            'category' => 'Category',
            'published' => 'Published',
            'published_yes' => 'Published',
            'published_no' => 'Not published',
        ],
        'fields' => [
            'first_name' => 'First name',
            'last_name' => 'Last name',
            'company' => 'Organization',
            'job_title' => 'Job title',
            'email' => 'Email',
            'country' => 'Country',
            'tagline' => 'One-line description',
            'tagline_help' => 'Up to 160 characters; shown under the name.',
            'bio' => 'Biography',
            'photo' => 'Photo',
            'photo_help' => 'JPEG, PNG or WebP up to 5 MB.',
            'social_links' => 'Social networks',
            'social_network' => 'Network',
            'social_url' => 'Address (https)',
            'add_social_link' => 'Add network',
            'categories' => 'Categories',
            'is_published' => 'Published',
        ],
        'networks' => [
            'linkedin' => 'LinkedIn',
            'x' => 'X',
            'instagram' => 'Instagram',
            'facebook' => 'Facebook',
            'youtube' => 'YouTube',
            'tiktok' => 'TikTok',
            'website' => 'Website',
        ],
        'errors' => [
            'email_taken' => 'A speaker with that email already exists in this event.',
            'social_network_repeated' => 'Each network can appear only once.',
            'social_url_https' => 'The address must be a valid https URL.',
            'social_url_domain' => 'The address must be from :network.',
            'category_invalid' => 'The category does not exist in this event.',
        ],
    ],
    // --- End of speakers ---
];
