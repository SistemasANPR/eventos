<?php

declare(strict_types=1);

return [
    'switch' => [
        'to_platform' => 'Go to the platform panel',
        'to_organization' => 'Go to my organization',
    ],

    'reset_request' => [
        'sent' => [
            'title' => 'Check your email',
            'body' => 'If the email is registered, we sent you a link to reset your password.',
        ],
    ],
    'reset_invalid' => 'The link is invalid or has expired. Request a new one.',
    'password_reset' => [
        'subject' => 'Reset your password',
        'greeting' => 'Hello',
        'line' => 'We received a request to reset the password of your account.',
        'action' => 'Reset password',
        'expiry' => 'The link expires in :minutes minutes and can only be used once.',
        'ignore' => 'If you did not request it, ignore this message: your password does not change.',
    ],
    'new_device' => [
        'subject' => 'New sign-in to your account',
        'greeting' => 'Hello',
        'line' => 'Someone signed in to your account from a device we do not recognize.',
        'when' => 'Date: :date (UTC)',
        'device' => 'Approximate device: :device',
        'ignore' => 'If it was you, no action is needed. If not, change your password right away.',
    ],
];
