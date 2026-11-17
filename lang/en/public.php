<?php

declare(strict_types=1);

/** Public site texts (React). They reach the client as shared Inertia props. */
return [
    'layout' => [
        'skip_to_content' => 'Skip to content',
        'footer' => 'Event management platform',
    ],
    'event' => [
        'when' => 'Dates and time',
        'where' => 'Venue',
        'timezone' => 'Time zone',
        'language' => 'Language',
        'language_option' => 'View in :language',
        'no_venue' => 'Venue to be confirmed',
        'privacy_link' => 'Privacy notice',
    ],
    'privacy' => [
        'title' => 'Privacy notice',
        'version' => 'Version :version',
        'published_on' => 'Published on :date',
        'back_to_event' => 'Back to the event',
        'shown_in_base_language' => 'This notice is shown in the base language of the event.',
    ],
    'call' => [
        'type' => 'Submission type',
        'status' => 'Status',
        'opens_at' => 'Opens',
        'closes_at' => 'Closes',
        'results_at' => 'Results',
        'timezone' => 'Time zone',
        'tracks' => 'Tracks',
        'no_tracks' => 'This call has no tracks yet.',
        'back_to_event' => 'Back to the event',
        'state' => [
            'scheduled' => 'Coming soon',
            'open' => 'Open',
            'paused' => 'Paused',
            'closed' => 'Closed',
        ],
        'state_hint' => [
            'scheduled' => 'The call has not opened yet.',
            'open' => 'The call is receiving proposals.',
            'paused' => 'Receiving proposals is paused for now.',
            'closed' => 'The call is no longer receiving proposals.',
        ],
    ],
    // Internal component catalog (`/components`): team tool, fictitious data.
    'catalog' => [
        'title' => 'Component catalog',
        'intro' => 'Inventory of the public site\'s own components, with a demo. Internal tool: not indexed and not linked from any menu.',
        'level_label' => 'Level',
        'category_label' => 'Category',
        'use_when' => 'Use when',
        'avoid_for' => 'Avoid for',
        'demo' => 'Demo',
        'no_demo' => 'No demo.',
        'level' => [
            'atom' => 'Atom',
            'molecule' => 'Molecule',
            'organism' => 'Organism',
            'template' => 'Template',
            'infrastructure' => 'Infrastructure',
        ],
        'demo_data' => [
            'rich_text' => '<p>We handle your data with <strong>care</strong> and <em>transparency</em>.</p><ul><li>First point</li><li>Second point with a <a href="https://example.test">link</a></li></ul>',
            'link' => 'A text link',
            'link_active' => 'Active option',
            'fact_label' => 'Venue',
            'fact_value' => 'Convention Center, Mexico City',
            'skip_hint' => 'Press the Tab key to reveal the link.',
            'event_name' => 'Urban Parks Congress',
            'event_description' => 'Three days to share experiences on the care and management of parks.',
            'event_venue' => 'Convention Center',
            'event_city' => 'Mexico City',
        ],
    ],
    'languages' => [
        'es' => 'Español',
        'en' => 'English',
        'pt' => 'Português',
    ],
];
