<?php

declare(strict_types=1);

namespace App\Enums;

/** Estado de un envío de correo (plan 008, tabla `mail_dispatches`). */
enum MailDispatchStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';
    case Throttled = 'throttled';
    case Disabled = 'disabled';
}
