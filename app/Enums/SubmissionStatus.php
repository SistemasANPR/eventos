<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Estado de una propuesta (glosario, plan 006 §4). En R0 solo se producen
 * `submitted` y `withdrawn`; el resto lo usan los planes 011 y 012. El `CHECK` de
 * la base admite los siete.
 */
enum SubmissionStatus: string implements HasColor, HasLabel
{
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case Accepted = 'accepted';
    case Waitlisted = 'waitlisted';
    case Rejected = 'rejected';
    case Confirmed = 'confirmed';
    case Withdrawn = 'withdrawn';

    /** Una decisión del comité cuyo resultado se avisa al autor al publicarlo (plan 012, DA-2). */
    public function isDecision(): bool
    {
        return in_array($this, [self::Accepted, self::Waitlisted, self::Rejected], true);
    }

    public function getLabel(): string
    {
        return __("submissions.status.{$this->value}");
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Submitted => 'gray',
            self::UnderReview => 'info',
            self::Accepted, self::Confirmed => 'success',
            self::Waitlisted => 'warning',
            self::Rejected => 'danger',
            self::Withdrawn => 'gray',
        };
    }
}
