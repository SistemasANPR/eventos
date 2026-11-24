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
        'reviewer_unavailable' => 'The reviewer of the rule is no longer an active member or a reviewer of this event',
    ],

    'errors' => [
        'reviewer_not_in_event' => 'Every reviewer must be an active member of the organization with the reviewer role in this event. Nothing was assigned.',
    ],

    'actions' => [
        'assign' => 'Assign reviewers',
        'assign_description' => 'Only the selected submissions are assigned. Withdrawn, already decided or invited ones are skipped and the result says so.',
        'assign_by_scope' => 'Assign by track or call',
        'assign_by_scope_description' => 'Assigns the eligible submissions of the track or call that exist now and saves a rule: those that arrive later are assigned to these reviewers automatically, without email. Removing the rule does not delete assignments.',
        'sync' => 'Assign new submissions',
        'sync_description' => 'Applies the scope rules now: assigns each reviewer the submissions of their track or call they do not have yet. Sends no email.',
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
        'not_in_event' => 'not assigned to this event',
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
        'rules_created' => 'Scope rules saved: :count. New submissions in the scope will be assigned automatically.',
        'notification_failed' => 'The notice could not be queued for :count reviewer(s); the assignments were saved.',
        'failed' => 'Nothing was assigned',
    ],

    'columns' => [
        'reviewers' => 'Reviewers',
        'coverage_incomplete' => 'Incomplete coverage',
    ],

    'invite' => [
        'action' => 'Invite reviewer',
        'description' => 'Adds the person as a reviewer of this event and emails them how to sign in. If they already belong to the organization they are only assigned to the event and no link is sent.',
        'submit' => 'Invite',
        'name' => 'Name',
        'email' => 'Email',
        'sent' => 'Invitation sent.',
        'already_member' => 'That person already belongs to the organization: they were assigned to this event and no link was sent.',
        'needed_title' => 'Nothing was assigned',
        'needed_body' => 'These people are not assigned to this event yet: :names. Invite them from «Reviewers» and assign again.',
        'go_to_reviewers' => 'Go to Reviewers',
    ],

    'reviewers' => [
        'navigation' => 'Reviewers',
        'heading' => 'Event reviewers',
        'columns' => [
            'name' => 'Reviewer',
            'email' => 'Email',
            'assigned' => 'Assigned submissions',
            'progress' => 'Progress',
        ],
        'no_assignments' => 'No submissions assigned',
        'progress' => ':percent % · :done of :total',
        'coverage_ok' => 'Every submission to review has at least the minimum number of reviewers.',
        'coverage_item' => ':scope «:name»: :incomplete of :total submissions to review have fewer reviewers than the minimum.',
        'empty_heading' => 'This event has no reviewers yet',
        'empty_description' => 'Invite a reviewer to be able to assign them submissions.',
    ],

    'rules' => [
        'navigation' => 'Assignment rules',
        'back' => 'Back to Reviewers',
        'columns' => [
            'reviewer' => 'Reviewer',
            'type' => 'Type',
            'scope' => 'Scope',
            'created_at' => 'Created',
        ],
        'remove' => 'Remove rule',
        'remove_description' => 'Submissions already assigned are kept; those that arrive later will no longer be assigned to this reviewer by this rule.',
        'removed' => 'Rule removed. Assignments already made are kept.',
        'empty_heading' => 'There are no assignment rules',
        'empty_description' => 'They are created when you assign reviewers by track or call.',
    ],
];
