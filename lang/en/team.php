<?php

declare(strict_types=1);

return [
    'set_password' => [
        'subject' => 'Set your password',
        'greeting' => 'Hello',
        'line' => 'You have been given access to the platform. Use the button to set your password; the link expires in 60 minutes.',
        'action' => 'Set password',
    ],
    'membership_added' => [
        'subject' => 'You are now part of :organization',
        'greeting' => 'Hello',
        'line' => 'The organization :organization gave you access to its team. Sign in with your usual account to see it.',
        'action' => 'Sign in',
    ],
    'roles' => [
        'org_admin' => 'Administrator',
        'organizer' => 'Organizer',
        'reviewer' => 'Reviewer',
    ],
    'status' => [
        'active' => 'Active',
        'deactivated' => 'Deactivated',
    ],
    'resource' => [
        'label' => 'Member',
        'plural' => 'Team',
        'name' => 'Name',
        'email' => 'Email',
        'role' => 'Role',
        'status' => 'Status',
    ],
    'invite' => [
        'action' => 'Add member',
        'submit' => 'Add member',
        'sent' => 'Member added. If the email is new they will receive a link to set their password.',
        'throttled' => 'Too many additions in a short time. Wait a minute and try again.',
        'already_member' => 'That email already belongs to this organization\'s team.',
        'deactivated_member' => 'That email belongs to a deactivated member of this organization. Use "Reactivate" in the team list.',
    ],
    'reactivate' => [
        'action' => 'Reactivate',
        'submit' => 'Reactivate',
        'done' => 'Member reactivated. They regain access with the confirmed role.',
    ],
    'resend' => [
        'action' => 'Resend invitation',
        'confirm' => 'The email to set the password will be sent again, with a new link that expires in 60 minutes.',
        'done' => 'Invitation resent. The person will receive an email to set their password.',
    ],
    'change_role' => [
        'action' => 'Change role',
        'done' => 'Role updated.',
    ],
    'deactivate' => [
        'action' => 'Deactivate',
        'confirm' => 'The person will lose access to this organization. Their history is kept.',
        'done' => 'Member deactivated.',
    ],
    'errors' => [
        'last_admin' => 'The organization must keep at least one active administrator.',
        'not_pending' => 'This person has already signed in: they can use "Forgot your password?" if they lost it.',
        'not_active' => 'The person is no longer an active member of this organization.',
        'not_deactivated' => 'The person is not deactivated in this organization: there is nothing to reactivate.',
    ],
];
