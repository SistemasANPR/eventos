<?php

declare(strict_types=1);

return [
    'name_required' => 'The event needs a name in at least one language.',
    'name_required_in_default_locale' => 'To publish, the event needs its name in the default language (:locale).',
    'only_draft_can_be_published' => 'Only a draft event can be published.',
    'only_published_can_be_archived' => 'Only a published event can be archived.',
    'slug_change_needs_confirmation' => 'This event is already published: changing its identifier breaks the links you already shared. Confirm the change to continue.',

    'navigation' => 'Events',
    'model' => 'event',
    'plural' => 'events',

    'sections' => [
        'general' => 'General information',
        'dates' => 'Dates',
        'venue' => 'Venue',
        'languages' => 'Languages and currency',
    ],

    'fields' => [
        'name_es' => 'Name (Spanish)',
        'name_en' => 'Name (English)',
        'name_pt' => 'Name (Portuguese)',
        'description_es' => 'Description (Spanish)',
        'description_en' => 'Description (English)',
        'description_pt' => 'Description (Portuguese)',
        'slug' => 'URL identifier',
        'slug_help' => 'Lowercase letters, numbers and hyphens. It forms the public link of the event.',
        'type' => 'Event type',
        'currency' => 'Currency',
        'starts_at' => 'Start',
        'ends_at' => 'End',
        'dates_help' => 'Local time of the event time zone.',
        'timezone' => 'Time zone',
        'venue_name' => 'Venue name',
        'venue_address' => 'Address',
        'city' => 'City',
        'locales' => 'Event languages',
        'default_locale' => 'Default language',
        'confirm_slug_change' => 'I confirm changing the identifier',
        'confirm_slug_change_help' => 'The event is already published: changing it breaks the links you already shared.',
        'public_url' => 'Public link',
        'status' => 'Status',
    ],

    'table' => [
        'empty_heading' => 'There are no events yet',
        'empty_description' => 'Create your first event to set up its call. This is where you will choose which event to work on.',
        'name' => 'Event',
        'starts_at' => 'Dates',
    ],

    'status' => [
        'draft' => 'Draft',
        'published' => 'Published',
        'archived' => 'Archived',
    ],

    'type' => [
        'congress' => 'Congress',
        'forum' => 'Forum',
        'workshop' => 'Workshop',
        'webinar' => 'Webinar',
        'expo' => 'Expo',
    ],

    'actions' => [
        'publish' => 'Publish',
        'publish_confirm' => 'Publish this event? Anyone with its link will be able to see it.',
        'archive' => 'Archive',
        'archive_confirm' => 'Archive this event? It will no longer be visible to the public; its data is kept.',
        'published' => 'Event published',
        'archived' => 'Event archived',
        'failed' => 'The action could not be completed',
    ],
];
