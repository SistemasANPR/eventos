<?php

declare(strict_types=1);

return [
    'profile' => [
        'title' => 'Organization details',
        'name' => 'Name',
        'slug' => 'Identifier',
        'slug_help' => 'The part of the web address that identifies the organization: 3 to 63 characters, lowercase letters, numbers and hyphens.',
        'slug_warning' => 'Changing the identifier changes the organization web addresses.',
        'slug_warning_detail' => 'The old addresses will stop working and are not redirected: links already shared, printed or saved will return a 404 error.',
    ],
    'slug' => [
        'invalid' => 'The identifier must be 3 to 63 characters: lowercase letters, numbers and single hyphens (no accents or spaces).',
        'reserved' => 'That identifier is reserved. Choose another one.',
    ],
];
