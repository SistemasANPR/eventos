<?php

declare(strict_types=1);

/*
 * Description of each catalog notification (plan 008). Shown on the panel's
 * catalog screen; it is not the email text.
 */
return [
    'invalid_variable' => 'Variables not allowed: :variables.',
    'auth' => [
        'password_reset' => [
            'name' => 'Password reset',
            'description' => 'Signed, single-use link to set a new password.',
            'trigger' => 'Someone asks to reset their password.',
            'recipient' => 'The person who asked for the reset.',
        ],
        'new_device_login' => [
            'name' => 'Sign-in from a new device',
            'description' => 'Security notice with the date and an approximate device.',
            'trigger' => 'Someone signs in from an unregistered device.',
            'recipient' => 'The account owner.',
        ],
    ],
    'team' => [
        'set_password' => [
            'name' => 'Team invitation: set password',
            'description' => 'Invitation with the link to set the password of a new account.',
            'trigger' => 'A new person is added to an organization.',
            'recipient' => 'The person who was added.',
        ],
        'membership_added' => [
            'name' => 'Team invitation: existing account',
            'description' => 'Notice that an organization added a person who already had an account.',
            'trigger' => 'Someone who already has an account is added to an organization.',
            'recipient' => 'The person who was added.',
        ],
    ],
    'submission' => [
        'received' => [
            'name' => 'Submission receipt',
            'description' => 'Confirms the submission was received and identifies it with a reference.',
            'trigger' => 'A submission is sent.',
            'recipient' => 'The person who sent the submission.',
        ],
        'access_link' => [
            'name' => 'Author access link',
            'description' => 'Single-use link to view the submitted proposal.',
            'trigger' => 'The author asks for their access link.',
            'recipient' => 'The person who sent the submission.',
        ],
        'new_for_organizers' => [
            'name' => 'New submission for the team',
            'description' => 'Tells the team that a submission arrived.',
            'trigger' => 'A submission is received.',
            'recipient' => 'Team members allowed to view submissions.',
        ],
        'updated' => [
            'name' => 'Submission edited',
            'description' => 'Tells that the author changed their submission.',
            'trigger' => 'The author edits their submission.',
            'recipient' => 'The author and team members allowed to view submissions.',
        ],
        'withdrawn' => [
            'name' => 'Submission withdrawn',
            'description' => 'Tells that the author withdrew their submission.',
            'trigger' => 'The author withdraws their submission.',
            'recipient' => 'The author and team members allowed to view submissions.',
        ],
    ],
];
