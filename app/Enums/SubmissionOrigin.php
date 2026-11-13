<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Origen de una propuesta (plan 018): envío público a una convocatoria o conferencista invitado. */
enum SubmissionOrigin: string implements HasLabel
{
    case Call = 'call';
    case Invited = 'invited';

    public function getLabel(): string
    {
        return __("submissions.origin.{$this->value}");
    }
}
