<?php

declare(strict_types=1);

namespace App\Filament\Resources\Submissions\Support;

use App\Filament\Resources\Events\EventResource;
use App\Models\EventActivity;
use App\Models\Submission;
use Illuminate\Support\Facades\Gate;

/**
 * Enlace de una propuesta a la actividad que nació de ella (plan 021, F6, CA-021-14). Solo se ofrece a
 * quien puede ver actividades (`EventActivityPolicy`): ocultar el enlace no sustituye a la Policy de la
 * página de destino, pero evita revelar a quien no ve el programa que una propuesta ya tiene actividad.
 * La actividad no tiene página propia, así que el enlace abre la lista del evento ya buscada por su título.
 */
final class SubmissionActivityLink
{
    /** @return array{title: string, url: string}|null */
    public static function for(Submission $submission): ?array
    {
        $activity = $submission->activity;

        if (! $activity instanceof EventActivity || ! Gate::allows('viewAny', EventActivity::class)) {
            return null;
        }

        return [
            'title' => $activity->title,
            'url' => EventResource::getUrl('activities', ['record' => $submission->event_id, 'tableSearch' => $activity->title]),
        ];
    }
}
