<?php

declare(strict_types=1);

namespace App\Support\Submissions;

use App\Enums\SubmissionStatus;
use App\Exceptions\Domain\InvalidSubmissionTransition;

/**
 * Tabla de transiciones de estado de una propuesta (plan 012, DA-3). Es la única definición: las
 * Actions de transición la consultan y la prueba unitaria la compara con el plan.
 *
 * - Entrada directa: `submitted` también se decide sin pasar por revisión (eventos sin comité).
 * - Reversas (`accepted`, `waitlisted` o `rejected` → `under_review`): las hace solo quien tiene
 *   `submissions.revert` (DA-1); aquí solo se distinguen con `isReversal()`.
 * - Cualquier estado hasta `confirmed` puede pasar a `withdrawn`; de `withdrawn` no se sale.
 * - Todo lo demás es inválido (por ejemplo `accepted` → `rejected` directa).
 */
final class SubmissionTransitions
{
    /** @var array<string, list<SubmissionStatus>> */
    private const REVERSIBLE = [
        'accepted' => [SubmissionStatus::UnderReview],
        'waitlisted' => [SubmissionStatus::UnderReview],
        'rejected' => [SubmissionStatus::UnderReview],
    ];

    /** @return list<SubmissionStatus> destinos permitidos desde `$from` */
    public static function allowedFrom(SubmissionStatus $from): array
    {
        return match ($from) {
            SubmissionStatus::Submitted => [
                SubmissionStatus::UnderReview,
                SubmissionStatus::Accepted,
                SubmissionStatus::Rejected,
                SubmissionStatus::Waitlisted,
                SubmissionStatus::Withdrawn,
            ],
            SubmissionStatus::UnderReview => [
                SubmissionStatus::Accepted,
                SubmissionStatus::Waitlisted,
                SubmissionStatus::Rejected,
                SubmissionStatus::Withdrawn,
            ],
            SubmissionStatus::Waitlisted => [
                SubmissionStatus::Accepted,
                SubmissionStatus::Rejected,
                SubmissionStatus::UnderReview,
                SubmissionStatus::Withdrawn,
            ],
            SubmissionStatus::Accepted, SubmissionStatus::Rejected => [
                SubmissionStatus::UnderReview,
                SubmissionStatus::Withdrawn,
            ],
            SubmissionStatus::Confirmed => [SubmissionStatus::Withdrawn],
            SubmissionStatus::Withdrawn => [],
        };
    }

    public static function isAllowed(SubmissionStatus $from, SubmissionStatus $to): bool
    {
        return in_array($to, self::allowedFrom($from), true);
    }

    /** Una reversa deshace una decisión: vuelve a `under_review` desde un estado decidido. */
    public static function isReversal(SubmissionStatus $from, SubmissionStatus $to): bool
    {
        return in_array($to, self::REVERSIBLE[$from->value] ?? [], true);
    }

    /** @throws InvalidSubmissionTransition si la transición no está en la tabla */
    public static function assertAllowed(SubmissionStatus $from, SubmissionStatus $to): void
    {
        if (! self::isAllowed($from, $to)) {
            throw new InvalidSubmissionTransition($from, $to);
        }
    }
}
