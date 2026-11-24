<?php

declare(strict_types=1);

/** Reviewer panel (plan 011, F4). */
return [
    'brand' => 'Review',
    'period_closed_error' => 'The review period for this event is closed: reviews can no longer be created or changed.',
    'no_published_rubric' => 'This submission type does not have a published rubric yet, so it cannot be reviewed.',
    'draft_after_submit' => 'The review was already submitted: to change it, review again and submit it once more.',
    'unknown_criterion' => 'An answer does not match any criterion of the rubric.',

    'dashboard' => [
        'navigation' => 'Home',
        'title' => 'My reviews',
        'summary' => 'Summary of my assigned submissions',
        'cards' => [
            'assigned' => 'Assigned',
            'pending' => 'Pending',
            'in_progress' => 'In progress',
            'submitted' => 'Submitted',
            'declined_conflict' => 'With conflict',
        ],
        'periods' => 'Review period',
        'period_open' => 'Open',
        'period_closed' => 'Closed',
        'period_closed_notice' => 'the review period is closed and grading is no longer possible.',
        'no_periods' => 'You are not assigned to any event as a reviewer yet.',
        'empty_heading' => 'You have no assigned submissions yet',
        'empty_description' => 'When the organizing team assigns submissions to you, they will appear here and you will get an email.',
        'go_to_list' => 'View my submissions',
    ],

    'list' => [
        'navigation' => 'Assigned submissions',
        'model' => 'assigned submission',
        'plural' => 'assigned submissions',
        'period_closed_notice' => 'The review period is closed for: :events. Grading is no longer possible.',
        'empty_heading' => 'No assigned submissions',
        'empty_description' => 'You have no assigned submissions with these filters.',
        'columns' => [
            'title' => 'Title',
            'event' => 'Event',
            'track' => 'Track',
            'call' => 'Call',
            'status' => 'Status',
        ],
        'actions' => [
            'review' => 'Grade',
            'continue' => 'Continue',
            'review_again' => 'Review again',
        ],
    ],

    'evaluate' => [
        'breadcrumb' => 'Review',
        'heading' => 'Review',
        'description' => 'Grade each criterion. You can save a draft and finish later; while the period is open you can review again.',
        'save_draft' => 'Save draft',
        'submit' => 'Submit review',
        'resubmit' => 'Submit again',
        'draft_saved' => 'Draft saved',
        'submitted' => 'Review submitted',
        'scale_hint' => 'Required: a number from :min to :max.',
        'cannot' => [
            'conflict' => 'This submission cannot be graded: you declared a conflict of interest or the assignment is no longer active.',
            'period_closed' => 'The review period for this event is closed: you can read the submission but no longer grade it.',
            'no_rubric' => 'This submission type does not have a published rubric yet, so it cannot be graded for now.',
        ],
    ],
];
