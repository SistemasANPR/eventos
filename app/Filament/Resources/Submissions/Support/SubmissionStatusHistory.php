<?php

declare(strict_types=1);

namespace App\Filament\Resources\Submissions\Support;

use App\Models\Event;
use App\Models\Submission;
use App\Support\Events\EventTime;

/**
 * Filas del historial de estados de una propuesta para su detalle (plan 012, H-012-1): cuándo (en la zona
 * del evento), qué cambió, quién y el comentario para el autor. Todo texto se imprime escapado en la vista.
 */
final class SubmissionStatusHistory
{
    /** @return list<array{when: string, from: string, to: string, actor: string|null, comment: string|null}> */
    public static function for(Submission $submission, Event $event): array
    {
        $time = EventTime::for($event);

        return $submission->statusChanges()->with('actor')->get()
            ->map(static fn ($change): array => [
                'when' => $time->dateTime($change->created_at, __('submission_panel.long_date_format'), ' '),
                'from' => $change->from_status->getLabel(),
                'to' => $change->to_status->getLabel(),
                'actor' => $change->actor?->name,
                'comment' => $change->author_comment,
            ])
            ->all();
    }
}
