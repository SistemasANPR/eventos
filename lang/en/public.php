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
    'languages' => [
        'es' => 'Español',
        'en' => 'English',
        'pt' => 'Português',
    ],
];
