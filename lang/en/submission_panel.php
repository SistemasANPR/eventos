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
];
