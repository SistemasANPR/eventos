<?php

declare(strict_types=1);

/* Assigning reviewers to submissions (plan 011, F3a). */
return [
    'status' => [
        'pending' => 'Pending',
        'in_progress' => 'In progress',
        'submitted' => 'Submitted',
        'declined_conflict' => 'Conflict declared',
        'cancelled' => 'Cancelled',
    ],

    'skip_reasons' => [
        'not_from_call' => 'Invited submission: not reviewed',
        'ineligible_status' => 'Submission withdrawn or already decided',
        'conflict_of_interest' => 'Conflict of interest: the reviewer shares an email with a participant',
        'already_assigned' => 'Already assigned',
        'previously_cancelled' => 'Assignment cancelled earlier with its review kept',
    ],

    'errors' => [
        'reviewer_not_in_event' => 'Every reviewer must be an active member of the organization with the reviewer role in this event. Nothing was assigned.',
    ],

    'actions' => [
        'assign' => 'Assign reviewers',
        'assign_description' => 'Only the selected submissions are assigned. Withdrawn, already decided or invited ones are skipped and the result says so.',
        'assign_by_scope' => 'Assign by track or call',
        'assign_by_scope_description' => 'Assigns, once, the eligible submissions of the track or call that exist now. Those that arrive later are not assigned automatically.',
        'remove' => 'Remove reviewer',
        'remove_description' => 'A pending assignment is deleted; if the reviewer already started or submitted, it is cancelled and their review is kept without counting in the results.',
    ],

    'form' => [
        'scope' => 'Scope',
        'scope_track' => 'Track',
        'scope_call' => 'Call',
        'track' => 'Track',
        'call' => 'Call',
        'reviewers' => 'Reviewers',
        'no_reviewers' => 'This event has no assigned reviewers yet.',
        'assignment' => 'Assigned reviewer',
    ],

    'removal' => [
        'deleted' => 'Assignment deleted.',
        'cancelled' => 'Assignment cancelled; their review is kept.',
    ],

    'report' => [
        'title' => 'Reviewer assignment',
        'assigned' => 'Assignments created: :count.',
        'skipped' => 'Skipped: :count.',
        'more' => '… and :count more.',
        'notification_failed' => 'The notice could not be queued for :count reviewer(s); the assignments were saved.',
        'failed' => 'Nothing was assigned',
    ],

    'columns' => [
        'reviewers' => 'Reviewers',
        'coverage_incomplete' => 'Incomplete coverage',
    ],
];
