<?php

declare(strict_types=1);

return [
    'body_required' => 'The notice needs text in at least one language.',
    'draft_already_open' => 'This event already has an open draft notice. Publish or edit it before creating another.',
    'published_is_immutable' => 'A published version cannot be edited. Create a new version from it.',
    'already_published' => 'This version is already published.',
    'default_language_required' => 'To publish, the notice needs its text in the default language of the event (:locale).',
    'relation_title' => 'Privacy notice',
    'new_draft' => 'New draft',
    'edit_draft' => 'Edit draft',
    'empty_heading' => 'This event has no privacy notice yet',
    'empty_description' => 'Create a draft, review it and publish it. A call cannot be opened without a published notice.',
    'columns' => [
        'version' => 'Version',
        'status' => 'Status',
        'published_at' => 'Published (UTC)',
        'published_by' => 'Published by',
    ],
    'status' => [
        'draft' => 'Draft',
        'published' => 'Published',
    ],
    'fields' => [
        'body_es' => 'Text (Spanish)',
        'body_en' => 'Text (English)',
        'body_pt' => 'Text (Portuguese)',
    ],
    'actions' => [
        'publish' => 'Publish',
        'publish_confirm' => 'Publish this version? It will be visible to the public and can no longer be edited; to change it you will create a new version.',
        'published' => 'Notice published',
        'saved' => 'Draft saved',
        'failed' => 'The action could not be completed',
    ],
];
