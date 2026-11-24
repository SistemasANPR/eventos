<?php

declare(strict_types=1);

return [
    'navigation' => 'Rubrics',
    'model' => 'rubric',
    'plural' => 'rubrics',
    'heading' => 'Review rubrics',
    'create_title' => 'New rubric',
    'edit_title' => 'Edit rubric',

    'sections' => [
        'general' => 'Rubric details',
        'criteria' => 'Criteria',
    ],
    'status' => [
        'draft' => 'Draft',
        'published' => 'Published',
    ],
    'kinds' => [
        'scale' => 'Score',
        'text' => 'Comment',
    ],
    'table' => [
        'submission_type' => 'Submission type',
        'version' => 'Version',
        'status' => 'Status',
        'blind_review' => 'Blind review',
        'min_reviewers' => 'Minimum reviewers',
        'criteria' => 'Criteria',
        'yes' => 'Yes',
        'no' => 'No',
        'empty_heading' => 'No rubrics yet',
        'empty_description' => 'Create one rubric per submission type and publish it so reviewers can score.',
    ],
    'fields' => [
        'submission_type' => 'Submission type',
        'min_reviewers' => 'Minimum reviewers per submission',
        'min_reviewers_help' => 'How many reviews each submission needs to count as covered.',
        'discrepancy_threshold' => 'Discrepancy threshold',
        'discrepancy_threshold_help' => 'Difference, in scale points, from which two reviews are flagged as discrepant. It must be greater than zero and not greater than the span of the shortest scale.',
        'blind_review' => 'Blind review',
        'blind_review_help' => 'The reviewer does not see who sent the submission.',
        'criteria' => 'Criteria',
        'criteria_help' => 'A score criterion is averaged using its weight; a comment criterion is free text and is not averaged. The rubric needs at least one score criterion to be published.',
        'add_criterion' => 'Add criterion',
        'untitled' => 'Untitled criterion',
        'label_es' => 'Label (Spanish)',
        'label_en' => 'Label (English)',
        'label_pt' => 'Label (Portuguese)',
        'kind' => 'Criterion type',
        'weight' => 'Weight',
        'weight_help' => 'Greater than zero.',
        'scale_min' => 'Scale: minimum',
        'scale_max' => 'Scale: maximum',
    ],
    'blind' => [
        'warning' => 'Blind review does not hide everything automatically.',
        'warning_detail' => 'The title and free-text fields are not filtered, except those you mark as "identifies the author" in the submission form.',
        'marked_fields' => 'Fields currently marked in this event\'s forms: :fields.',
        'no_marked_fields' => 'No field in this event\'s forms is marked as "identifies the author".',
        'saved_warning' => 'Remember: with blind review, the title and free-text fields are not hidden unless marked as "identifies the author".',
    ],
    'versioning' => [
        'notice' => 'This rubric already has reviews.',
        'notice_detail' => 'Saving creates a new draft version; the current version stays intact with its reviews.',
    ],
    'actions' => [
        'create' => 'New rubric',
        'edit' => 'Edit',
        'publish' => 'Publish',
        'publish_help' => 'The published rubric becomes the current one for its submission type and is revalidated before publishing.',
        'published' => 'Rubric published.',
        'new_version' => 'New version',
        'saved_new_version' => 'Version :version was created as a draft; the previous one was left intact.',
        'back' => 'Back to rubrics',
    ],
];
