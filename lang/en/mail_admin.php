<?php

declare(strict_types=1);

/*
 * Panel screens for the organization's emails (plan 008, H-008-3, H-008-4 and H-008-5):
 * catalog with status, template editing and disabling non-critical emails.
 */
return [
    'navigation' => 'Emails',
    'catalog' => [
        'title' => 'System emails',
        'subtitle' => 'These are all the emails the platform sends. Critical ones cannot be disabled.',
        'empty' => 'There are no registered notifications.',
        'columns' => [
            'name' => 'Email',
            'recipient' => 'Sent to',
            'critical' => 'Critical',
            'status' => 'Status',
            'languages' => 'Customized in',
        ],
        'critical_yes' => 'Critical',
        'critical_no' => 'Optional',
        'no_languages' => '—',
    ],
    'status' => [
        'default' => 'Default',
        'customized' => 'Customized',
        'disabled' => 'Disabled',
    ],
    'actions' => [
        'edit' => 'View or edit',
        'view' => 'View',
        'disable' => 'Disable',
        'enable' => 'Enable',
        'restore' => 'Restore default',
        'save' => 'Save template',
        'preview' => 'Preview',
        'back' => 'Back to the catalog',
        'close' => 'Close',
    ],
    'confirm' => [
        'disable_heading' => 'Disable this email',
        'disable_body' => 'It will stop being sent to anyone in your organization until you enable it again.',
        'enable_heading' => 'Enable this email',
        'enable_body' => 'It will be sent again when the event that triggers it happens.',
        'restore_heading' => 'Restore the default text',
        'restore_body' => 'Your customization of this email will be deleted in every language and the system text will be used.',
        'restore_locale_body' => 'Your customization of this email will be deleted in the language you have open and the system text will be used.',
    ],
    'notifications' => [
        'disabled' => 'Email disabled.',
        'enabled' => 'Email enabled.',
        'critical' => 'This email is critical and cannot be disabled.',
        'saved' => 'Template saved.',
        'restored' => 'The default text was restored.',
        'nothing_to_restore' => 'There was nothing to restore.',
        'invalid' => 'Check the highlighted fields.',
    ],
    'edit' => [
        'title' => 'Email: :name',
        'read_only' => 'Your role only allows viewing this template. To change it, ask an organization administrator.',
        'sections' => [
            'content' => 'Content',
            'sender' => 'Sender',
        ],
        'fields' => [
            'locale' => 'Language',
            'subject' => 'Subject',
            'body' => 'Message',
            'from_name' => 'Sender name',
            'reply_to' => 'Reply to',
        ],
        'help' => [
            'subject' => 'A single line. You can use variables such as {{ author_name }}.',
            'body' => 'Text with Markdown formatting (bold, lists and links). HTML is not allowed. Use only the allowed variables.',
            'from_name' => 'Name the recipient sees. The sending address does not change.',
            'reply_to' => 'Address that replies will go to. Leave it empty to use the default.',
            'variables' => 'Allowed variables: :variables',
        ],
        'source' => [
            'organization' => 'Using your organization\'s custom template.',
            'system' => 'Using the system default text. When you save, your version will be created.',
        ],
        'preview' => [
            'heading' => 'Preview with sample data',
            'subject' => 'Subject',
            'note' => 'This is only an example: no email is sent.',
        ],
    ],
];
