<?php

declare(strict_types=1);

return [
    'title_required' => 'The call needs a title in at least one language.',
    'only_draft_can_be_published' => 'Only a draft call can be published.',
    'privacy_notice_missing' => 'The event must have its privacy notice published in every language of the call. It is missing in: :locales.',

    'status' => [
        'draft' => 'Draft',
        'published' => 'Published',
        'paused' => 'Paused',
        'closed' => 'Closed',
    ],

    'type' => [
        'session' => 'Session',
        'panel' => 'Panel',
        'poster' => 'Poster',
        'workshop' => 'Workshop',
    ],
    'relation_title' => 'Calls',
    'new_call' => 'New call',
    'empty_heading' => 'This event has no calls yet',
    'empty_description' => 'Create a call, set its dates and languages, and publish it once the privacy notice is published.',
    'columns' => [
        'title' => 'Call',
        'type' => 'Type',
        'status' => 'Status',
        'public_state' => 'Public state',
        'period' => 'Period (event time)',
    ],
    'state' => [
        'scheduled' => 'Scheduled',
        'open' => 'Open',
        'paused' => 'Paused',
        'closed' => 'Closed',
    ],
    'fields' => [
        'submission_type' => 'Submission type',
        'locales' => 'Call languages',
        'title_es' => 'Title (Spanish)',
        'title_en' => 'Title (English)',
        'title_pt' => 'Title (Portuguese)',
        'description_es' => 'Description (Spanish)',
        'description_en' => 'Description (English)',
        'description_pt' => 'Description (Portuguese)',
        'opens_at' => 'Opens',
        'closes_at' => 'Closes',
        'results_at' => 'Results',
    ],
    'actions' => [
        'edit' => 'Edit',
        'publish' => 'Publish',
        'publish_confirm' => 'Publish this call? It will open and close by itself on the given dates (event time).',
        'published' => 'Call published',
        'saved' => 'Call saved',
        'failed' => 'The action could not be completed',
    ],
];
