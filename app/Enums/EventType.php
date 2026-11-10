<?php

declare(strict_types=1);

namespace App\Enums;

enum EventType: string
{
    case Congress = 'congress';
    case Forum = 'forum';
    case Workshop = 'workshop';
    case Webinar = 'webinar';
    case Expo = 'expo';
}
