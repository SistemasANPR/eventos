<?php

declare(strict_types=1);

return [
    'brand' => 'Platform',

    'status' => ['active' => 'Active', 'suspended' => 'Suspended'],

    'yes' => 'Yes',
    'no' => 'No',

    'users' => [
        'label' => 'User',
        'plural' => 'Users',
        'empty' => 'There are no users yet',
        'memberships' => 'Memberships',
        'columns' => [
            'name' => 'Name',
            'email' => 'Email',
            'platform' => 'Platform user',
            'organizations' => 'Active organizations',
        ],
    ],

    'events' => [
        'label' => 'Event',
        'plural' => 'Events',
        'empty' => 'There are no events yet',
        'columns' => [
            'organization' => 'Organization',
            'name' => 'Event',
            'status' => 'Status',
            'dates' => 'Dates (event time)',
            'locales' => 'Languages',
        ],
    ],

    'audit' => [
        'label' => 'Audit record',
        'plural' => 'Audit',
        'empty' => 'There are no audit records',
        'columns' => [
            'date' => 'Date',
            'event' => 'Event',
            'actor' => 'Actor',
            'organization' => 'Organization',
            'subject' => 'Subject',
            'changes' => 'Changes',
        ],
        'filters' => [
            'date' => 'Date',
            'from' => 'From',
            'until' => 'Until',
        ],
    ],

    'organizations' => [
        'label' => 'Organization',
        'plural' => 'Organizations',
        'empty' => 'There are no organizations yet',
        'admins' => 'Administrators',
        'recent_audit' => 'Recent audit',
        'columns' => [
            'name' => 'Name',
            'slug' => 'Identifier',
            'status' => 'Status',
            'members' => 'Active members',
            'events' => 'Events',
        ],
        'fields' => [
            'slug' => 'URL identifier',
            'slug_help' => 'Lowercase letters, numbers and hyphens. It cannot be changed later.',
            'name' => 'Organization name',
            'admin_name' => 'First administrator name',
            'admin_email' => 'First administrator email',
            'admin_email_help' => 'They will receive an email to set their password.',
            'reason' => 'Reason for the suspension',
        ],
        'create' => 'New organization',
        'created' => 'Organization created',
        'already_exists' => 'An organization with that identifier already exists',
        'suspend' => 'Suspend',
        'suspend_help' => 'Its members will lose access to the panel and the public site will stop responding. Data is not touched.',
        'suspended' => 'Organization suspended',
        'reactivate' => 'Reactivate',
        'reactivate_help' => 'Its members will get their access back and the public site will respond again.',
        'reactivated' => 'Organization reactivated',
    ],
];
