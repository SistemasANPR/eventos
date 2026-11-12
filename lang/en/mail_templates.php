<?php

declare(strict_types=1);

/*
 * System default templates, in English (plan 008, DA-4). Markdown body; the
 * `{{ variable }}` placeholders are the whitelist of each catalog descriptor.
 * They are not editable from the interface.
 */
return [
    'auth' => [
        'password_reset' => [
            'subject' => 'Reset your password',
            'body' => "Hello {{ user_name }},\n\nWe received a request to reset your password. Use this link, which expires in {{ expires_in_minutes }} minutes:\n\n[{{ reset_url }}]({{ reset_url }})\n\nIf this wasn't you, ignore this email.",
        ],
        'new_device_login' => [
            'subject' => 'Sign-in from a new device',
            'body' => "Hello {{ user_name }},\n\nYour account was signed in to on {{ occurred_at }} (UTC) from {{ device }}.\n\nIf it was you, no action is needed. If not, change your password right away.",
        ],
    ],
    'team' => [
        'set_password' => [
            'subject' => 'You were added to {{ organization_name }}',
            'body' => "Hello {{ member_name }},\n\n{{ organization_name }} added you with the role {{ role_name }}. Set your password with this link:\n\n[{{ set_password_url }}]({{ set_password_url }})",
        ],
        'membership_added' => [
            'subject' => 'You are now part of {{ organization_name }}',
            'body' => "Hello {{ member_name }},\n\n{{ organization_name }} added you with the role {{ role_name }}. Sign in with your existing account:\n\n[{{ login_url }}]({{ login_url }})",
        ],
    ],
    'submission' => [
        'received' => [
            'subject' => 'We received your submission for {{ event_name }}',
            'body' => "Hello {{ author_name }},\n\nWe received your submission \"{{ submission_title }}\". Your reference is {{ submission_reference }}.",
        ],
        'access_link' => [
            'subject' => 'Your access link for {{ event_name }}',
            'body' => "Hello {{ author_name }},\n\nUse this single-use link to view your submission. It expires in {{ expires_in_minutes }} minutes:\n\n[{{ access_url }}]({{ access_url }})",
        ],
        'new_for_organizers' => [
            'subject' => 'New submission in {{ event_name }}',
            'body' => "{{ author_name }} sent the submission \"{{ submission_title }}\".\n\nReview it in the panel: [{{ panel_url }}]({{ panel_url }})",
        ],
        'updated' => [
            'subject' => 'Submission edited in {{ event_name }}',
            'body' => "{{ author_name }} edited the submission \"{{ submission_title }}\".\n\nReview it in the panel: [{{ panel_url }}]({{ panel_url }})",
        ],
        'withdrawn' => [
            'subject' => 'Submission withdrawn in {{ event_name }}',
            'body' => "{{ author_name }} withdrew the submission \"{{ submission_title }}\".\n\nView it in the panel: [{{ panel_url }}]({{ panel_url }})",
        ],
    ],
];
