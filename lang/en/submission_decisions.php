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

    'bulk' => [
        'accept' => 'Accept selected',
        'waitlist' => 'Waitlist selected',
        'reject' => 'Reject selected',
        'filtered' => 'Decide on the filter',
        'filtered_description' => 'Decides on every submission the list shows with the filters applied (up to :max per operation). Those that cannot move to the chosen status are skipped and reported.',
        'apply' => 'Decide',
        'to' => 'Decision',
        'pick_target' => 'Choose the decision to see how many submissions it will affect.',
        'comment_help' => 'Plain text; the same comment is saved on every submission and sent when the result is announced.',
        'preview' => ':count submission(s) will be decided as ":to" (:by_status).',
        'preview_omitted' => ':count that cannot move to that status will be skipped.',
        'preview_notice' => 'All are decided in a single operation and can be reverted as a batch. Authors get no email until the call results are published.',
        'done_title' => 'Bulk decision applied',
        'done' => ':count submission(s) moved to ":to".',
        'published_now' => 'The call had already published its results: :count author(s) were notified on deciding.',
        'omitted' => ':count submission(s) that cannot move to ":to" were skipped (:by_status).',
        'revert_batch' => 'Revert batch',
        'revert_batch_description' => 'Sends the submissions of a bulk decision back to review. Those that changed status afterwards are left alone and reported.',
        'batch' => 'Batch',
        'batch_summary' => ':count submission(s) → ":to"',
        'revert_title' => 'Batch reversal',
        'reverted' => ':count submission(s) went back to review.',
        'reverted_skipped' => ':count submission(s) were skipped because they are no longer in the status the batch left them in:',
        'more' => 'and :count more.',
        'skip_reasons' => [
            'status_changed' => 'its status changed after the batch',
        ],
        'errors' => [
            'invalid_target' => 'Choose a valid decision.',
            'none_eligible' => 'None of the submissions can move to ":to".',
            'not_eligible' => ':count submission(s) cannot move to ":to" and nothing was changed: :list',
            'and_more' => 'and :count more',
        ],
    ],

    'publish' => [
        'action' => 'Publish results',
        'description' => 'Makes the call results public and emails every author with a decided submission. Later decisions are announced as they are made.',
        'submit' => 'Publish and notify',
        'call' => 'Call',
        'pick_call' => 'Choose the call to see how many authors will be notified.',
        'nothing_pending' => 'There are no results waiting to be announced.',
        'preview' => ':count author(s) will be notified (:by_status).',
        'results_at' => 'Expected results date: :date.',
        'already' => 'This call had already published its results.',
        'title' => 'Results published',
        'done' => ':count author(s) were notified.',
        'failed' => ':count author(s) could not be notified: they stay pending and are retried when you publish again.',
    ],
];
