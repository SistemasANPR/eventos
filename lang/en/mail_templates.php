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
        'two_factor_enabled' => [
            'subject' => 'You turned on two-step verification',
            'body' => "Hello {{ user_name }},\n\nTwo-step verification was turned on for your account on {{ occurred_at }} (UTC).\n\nIf it was not you, tell an administrator of your organization right away.",
        ],
        'two_factor_reset' => [
            'subject' => 'Your two-step verification was reset',
            'body' => "Hello {{ user_name }},\n\nAn administrator reset the two-step verification of your account on {{ occurred_at }} (UTC). The next time you sign in you will have to set it up again.\n\nIf you did not expect this, tell an administrator of your organization right away and change your password.",
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
            'body' => "Hello {{ author_name }},\n\nThank you for taking part! We confirm that your registration has been received.\n\n**Event:** {{ event_name }}\n\n**Call:** {{ call_title }}\n\n**Submission type:** {{ submission_type }}\n\n**Topic:** {{ track_name }}\n\n**Your submission:** \"{{ submission_title }}\"\n\n**Your reference:** {{ submission_reference }}\n\nKeep your reference: you will need it if you write to us about your submission. Our team will review it and let you know the next steps.",
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
        'withdrawn_author' => [
            'subject' => 'You withdrew your submission in {{ event_name }}',
            'body' => 'Hi {{ author_name }},

We confirm you withdrew your submission "{{ submission_title }}". It no longer takes part in the call.

If you need to look at it again, request a new access link: [{{ request_link_url }}]({{ request_link_url }})',
        ],
        'accepted' => [
            'subject' => 'Your submission was accepted at {{ event_name }}',
            'body' => 'Hi {{ author_name }},

Good news: your submission "{{ submission_title }}" was accepted at {{ event_name }}.

{{ author_comment }}

To look at your submission, request a one-time access link: [{{ request_link_url }}]({{ request_link_url }})',
        ],
        'rejected' => [
            'subject' => 'Result of your submission at {{ event_name }}',
            'body' => 'Hi {{ author_name }},

Thank you for taking part. We are sorry to tell you that your submission "{{ submission_title }}" was not selected at {{ event_name }}.

{{ author_comment }}

To look at your submission, request a one-time access link: [{{ request_link_url }}]({{ request_link_url }})',
        ],
        'waitlisted' => [
            'subject' => 'Your submission is on the waitlist at {{ event_name }}',
            'body' => 'Hi {{ author_name }},

Your submission "{{ submission_title }}" is on the waitlist at {{ event_name }}. If a spot opens up, we will let you know.

{{ author_comment }}

To look at your submission, request a one-time access link: [{{ request_link_url }}]({{ request_link_url }})',
        ],
    ],
    'call' => [
        'closing_reminder' => [
            'subject' => 'The {{ event_name }} call closes on {{ closing_date }}',
            'body' => 'Hi {{ author_name }},

A reminder that the call for submissions of {{ event_name }} closes on {{ closing_date }}.

{{ edit_hint }}

To look at your submission, request a one-time access link: [{{ request_link_url }}]({{ request_link_url }})',
        ],
    ],
    'reviewer' => [
        'assignment' => [
            'subject' => 'You have submissions to review in {{ event_name }}',
            'body' => "Hi {{ reviewer_name }},\n\nYou were assigned {{ assigned_count }} submission(s) to review in {{ event_name }}.\n\nOpen your dashboard to see them: [{{ dashboard_url }}]({{ dashboard_url }})",
        ],
        'invitation' => [
            'subject' => '{{ organization_name }} invited you to review submissions',
            'body' => "Hi {{ reviewer_name }},\n\n{{ organization_name }} invited you to review submissions of {{ event_name }}.\n\nUse this link to open your panel. If you do not have a password yet, you can set it there (the link expires in {{ expires_in_minutes }} minutes):\n\n[{{ access_url }}]({{ access_url }})\n\nIf you were not expecting this invitation, ignore this email.",
        ],
    ],
];
