<?php

declare(strict_types=1);

return [
    'profile' => [
        'title' => 'Organization details',
        'name' => 'Name',
        'slug' => 'Identifier',
        'slug_help' => 'It is part of the public addresses and cannot be changed here.',
    ],
    'slug' => [
        'invalid' => 'The identifier must be 3 to 63 characters: lowercase letters, numbers and single hyphens (no accents or spaces).',
        'reserved' => 'That identifier is reserved. Choose another one.',
    ],
];
