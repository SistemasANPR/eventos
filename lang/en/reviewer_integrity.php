<?php

declare(strict_types=1);

/* Conflict of interest and blind review in the reviewer panel (plan 011, F5). */
return [
    'conflict' => [
        'action' => 'Declare conflict',
        'heading' => 'Declare a conflict of interest',
        'description' => 'If you know the authors or have an interest in this submission, declare it: you will no longer be able to grade it or download its files, and the organizing team will see the notice so it can assign it to someone else. It cannot be undone from this panel.',
        'confirm' => 'Declare conflict',
        'declared' => 'Conflict declared. The organizing team will see the notice.',
        'not_allowed' => 'The conflict could not be declared: the assignment was already submitted, cancelled or declared. If you already submitted your review, ask the organizing team to cancel the assignment.',
    ],

    'blind' => [
        'notice' => 'Blind review: the authors\' details and the fields that identify them are not shown in this review.',
    ],

    'organizer' => [
        'conflicts' => 'Declared conflicts: :count',
    ],
];
