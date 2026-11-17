<?php

declare(strict_types=1);

return [
    'navigation' => 'Submissions',
    'model' => 'submission',
    'plural' => 'submissions',
    'empty' => 'There are no submissions for this event yet',

    'columns' => [
        'title' => 'Title',
        'call' => 'Call',
        'type' => 'Type',
        'track' => 'Track',
        'status' => 'Status',
        'submitted_at' => 'Submitted',
    ],

    'filters' => [
        'range' => 'Submission date',
        'from' => 'From',
        'until' => 'Until',
        'invalid' => 'The filter is not valid. Check the values and try again.',
    ],

    'detail' => [
        'general' => 'General information',
        'answers' => 'Answers',
        'participants' => 'Participants',
        'primary' => 'Primary',
        'retired' => 'field removed from the form',
        'yes' => 'Yes',
        'no' => 'No',
        'no_answers' => 'This submission has no answers.',
        'no_participants' => 'This submission has no participants.',
        'files_attached' => '{0} No attached files|{1} :count attached file|[2,*] :count attached files',
    ],
];
