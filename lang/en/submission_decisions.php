<?php

declare(strict_types=1);

return [
    'errors' => [
        'invalid_transition' => 'A submission cannot go from ":from" to ":to".',
        'status_conflict' => 'Someone else changed the status of this submission before you. Reload the page and check its current status.',
        'confirmation_required' => 'This decision was already published and the author has already been notified. Explicitly confirm that you want to revert it.',
    ],

    'author_comment' => [
        'label' => 'Comment for the author (optional)',
        'help' => 'Plain text; it is not interpreted as HTML or Markdown.',
        'max' => 'The comment cannot be longer than :max characters.',
    ],

    'actions' => [
        'accept' => 'Accept',
        'waitlist' => 'Waitlist',
        'reject' => 'Reject',
        'revert' => 'Revert decision',
        'accepted' => 'Submission accepted.',
        'waitlisted' => 'Submission waitlisted.',
        'rejected' => 'Submission rejected.',
        'reverted' => 'Decision reverted: the submission goes back to review.',
        'failed' => 'The status could not be changed',
        'revert_confirm' => 'The submission goes back to "Under review" and the history is kept.',
        'revert_published_warning' => 'The result of this submission was already published: the author has already been notified. Reverting does not send any email.',
        'confirm_published' => 'I understand the author has already been notified',
        'reason' => 'Reason (optional, internal)',
    ],

    'history' => [
        'heading' => 'Status history',
        'empty' => 'There are no status changes yet.',
        'columns' => [
            'when' => 'When',
            'change' => 'Change',
            'actor' => 'By',
            'comment' => 'Comment for the author',
        ],
        'system' => 'System',
    ],
];
