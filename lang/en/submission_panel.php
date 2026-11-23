<?php

declare(strict_types=1);

return [
    'navigation' => 'Submissions',
    'model' => 'submission',
    'plural' => 'submissions',
    'empty' => 'There are no submissions for this event yet',

    'date_format' => 'm/d/Y',
    'long_date_format' => 'F j, Y',

    'columns' => [
        'title' => 'Title',
        'call' => 'Call',
        'type' => 'Type',
        'track' => 'Track',
        'status' => 'Status',
        'submitted_at' => 'Submitted',
        'activity' => 'Activity',
    ],

    'filters' => [
        'range' => 'Submission date',
        'from' => 'From',
        'until' => 'Until',
        'show_accepted' => 'Also show accepted',
        'invalid' => 'The filter is not valid. Check the values and try again.',
    ],

    'detail' => [
        'general' => 'General information',
        'answers' => 'Answers',
        'participants' => 'Participants',
        'primary' => 'Primary',
        'retired' => 'field removed from the form',
        'yes' => 'Yes',
        'no' => 'No',
        'no_answers' => 'This submission has no answers.',
        'no_participants' => 'This submission has no participants.',
        'participant' => [
            'email' => 'Email address',
            'alt_email' => 'Alternative email',
            'mobile' => 'Mobile phone',
            'phone' => 'Phone with country code',
            'company' => 'Institution, organization or company',
            'job_title' => 'Job title or academic status',
            'country' => 'Country',
            'state' => 'State or region',
            'city' => 'City',
            'bio' => 'Professional profile',
        ],
        'files_attached' => '{0} No attached files|{1} :count attached file|[2,*] :count attached files',
    ],

    'summary' => [
        'action' => 'Summary',
        'total' => 'Total call submissions: :count',
        'by_track' => 'By track',
        'by_type' => 'By type',
        'by_day' => 'By submission day',
        'empty' => 'There are no call submissions for this event yet, so there is nothing to summarize. When they arrive, you will see how many there are by track, by type and by day.',
    ],

    'export' => [
        'action' => 'Export',
        'format' => 'Format',
        'format_invalid' => 'The export format is not valid.',
        'description' => 'The submissions matching the filters you have applied are exported. The file is prepared in the background and stays available for 24 hours under "Recent exports".',
        'queued_title' => 'Export in progress',
        'queued_body' => 'When the file is ready it will appear under "Recent exports".',
        'too_large' => 'The export has :rows submissions and the maximum is :max. Narrow the filters (call, track, type, status or dates) and try again.',
        'unavailable' => 'The export file is no longer available. Request a new export.',
        'recent' => 'Recent exports',
        'recent_empty' => 'There are no exports from the last 24 hours.',
        'recent_row' => ':date · :rows submissions · :format',
        'download' => 'Download',
        'recent_note' => 'Download links expire after 5 minutes and only work for the person who requested the export.',
    ],
];
