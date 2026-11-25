<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Estado de la asignación de un evaluador a una propuesta (plan 011, §4 y DA-7). `cancelled` conserva la
 * evaluación ya hecha y la excluye de los resultados; `declined_conflict` lo declara el evaluador (F5).
 */
enum ReviewAssignmentStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Submitted = 'submitted';
    case DeclinedConflict = 'declined_conflict';
    case Cancelled = 'cancelled';

    /** Si la asignación cuenta para la cobertura de la propuesta (CA-011-6): ni la cancelada ni la de conflicto. */
    public function countsForCoverage(): bool
    {
        return match ($this) {
            self::Pending, self::InProgress, self::Submitted => true,
            self::DeclinedConflict, self::Cancelled => false,
        };
    }

    /** @return list<string> valores que cuentan para la cobertura, para consultas */
    public static function coverageValues(): array
    {
        return array_map(
            static fn (self $status): string => $status->value,
            array_filter(self::cases(), static fn (self $status): bool => $status->countsForCoverage()),
        );
    }

    public function getLabel(): string
    {
        return __("review_assignments.status.{$this->value}");
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::InProgress => 'info',
            self::Submitted => 'success',
            self::DeclinedConflict => 'warning',
            self::Cancelled => 'danger',
        };
    }
}
