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
    'throttled' => [
        'title' => 'Too many attempts',
        'body_one' => 'Try again in :minutes minute.',
        'body_other' => 'Try again in :minutes minutes.',
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
    'two_factor_enabled' => [
        'subject' => 'You turned on two-step verification',
        'greeting' => 'Hello',
        'line' => 'Two-step verification was turned on for your account.',
        'when' => 'Date: :date (UTC)',
        'ignore' => 'If it was not you, tell an administrator of your organization right away.',
    ],
    'two_factor_reset' => [
        'subject' => 'Your two-step verification was reset',
        'greeting' => 'Hello',
        'line' => 'An administrator reset the two-step verification of your account.',
        'when' => 'Date: :date (UTC)',
        'next' => 'The next time you sign in you will have to set it up again with your authenticator app.',
        'ignore' => 'If you did not expect this, tell an administrator of your organization right away and change your password.',
    ],
    'login' => [
        'organization' => 'National Association of Parks and Recreation',
        'kicker' => 'EVENTS PLATFORM',
        'title' => 'Events',
        'tagline' => 'Calls for proposals, proposal review and the program of ANPR gatherings, in one place.',
        'modules' => ['call' => 'Call', 'review' => 'Review', 'program' => 'Program'],
        'heading' => 'Sign in to your account',
        'subheading' => 'Use the email address you were invited with.',
    ],
];
