<?php

declare(strict_types=1);

/*
 * Texts of the organization panel privacy screens (plan 014, F4 and F5): a person's data (export and
 * anonymize) and retention periods.
 */
return [
    'navigation' => [
        'personal_data' => 'A person\'s data',
        'retention' => 'Data retention',
    ],

    'reasons' => [
        'data_subject_request' => 'Request from the data subject',
        'consent_withdrawn' => 'Consent withdrawn',
        'legal_order' => 'Order from an authority',
        'other' => 'Other reason',
    ],

    'personal_data' => [
        'title' => 'A person\'s data',
        'subtitle' => 'Export everything this organization holds about a person, or anonymize it. The person is found by email across proposals, speakers, contacts, reviews, sent emails and panel access.',
        'fields' => [
            'email' => 'The person\'s email',
            'email_help' => 'The email they appear under in this organization.',
            'reason' => 'Reason',
            'confirmation' => 'Type the email again to confirm',
            'confirmation_help' => 'Anonymizing cannot be undone.',
        ],
        'actions' => [
            'export' => 'Export data',
            'anonymize' => 'Anonymize',
            'anonymize_heading' => 'Anonymize this person',
            'anonymize_body' => 'Their name, email and other personal data are replaced with non-identifying values and the files they uploaded are deleted. Totals, statuses and scores are kept. This cannot be undone.',
            'anonymize_submit' => 'Anonymize permanently',
            'download' => 'Download file',
        ],
        'notifications' => [
            'exported' => 'Export ready',
            'exported_body' => 'The download link works only for you and expires in :minutes minutes; the file is deleted after 24 hours.',
            'not_found' => 'There is no data for that person in this organization.',
            'anonymized' => 'Person anonymized',
            'anonymized_body' => 'Records changed: :total. Files deleted: :files.',
            'already_anonymized' => 'That person was already anonymized.',
            'account_kept' => 'Their sign-in account is kept because they also belong to another organization; here they lost their access.',
            'own_account' => 'You cannot anonymize your own account.',
            'platform_account' => 'A platform user cannot be anonymized from an organization.',
            'last_admin' => 'This is the last active administrator of the organization: appoint another one first.',
            'files_failed' => 'A file could not be deleted from storage. No data was changed; try again.',
            'salt_missing' => 'The platform has no anonymization salt configured. Tell whoever administers it.',
        ],
    ],

    'retention' => [
        'title' => 'Data retention',
        'subtitle' => 'Set how many days each category of data is kept. Once the period passes, a daily job anonymizes or deletes what expired. A category without a period is left alone.',
        'fields' => [
            'days' => 'Period in days',
            'days_help' => 'Leave empty to apply no period to this category.',
        ],
        'categories' => [
            'unaccepted_submissions' => 'Rejected or withdrawn proposals',
            'reviews' => 'Review comments',
            'mail_dispatches' => 'Email sends',
            'audit_log' => 'Organization audit log',
            'backups' => 'Backups (maximum days kept)',
        ],
        'descriptions' => [
            'unaccepted_submissions' => 'Their authors are anonymized and their files deleted; the proposal content is kept.',
            'reviews' => 'The text of the comments is replaced; scores are kept.',
            'mail_dispatches' => 'The send is unlinked from its recipient; the type, status and date are kept.',
            'audit_log' => 'Audit records of this organization older than the period are deleted.',
            'backups' => 'Declarative only: what the platform must configure at the backup provider. No job deletes backups.',
        ],
        'actions' => [
            'save' => 'Save periods',
        ],
        'notifications' => [
            'saved' => 'Periods saved',
        ],
        'validation' => [
            'days_range' => 'The period must be between :min and :max days.',
        ],
    ],
];
