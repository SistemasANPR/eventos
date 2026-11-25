<?php

declare(strict_types=1);

namespace App\Actions\Reviews;

use App\Http\Requests\Reviews\CloseReviewPeriodRequest;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Review;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Cierra el periodo de evaluación de un evento (plan 011, H-011-6, CA-011-26): a partir de ahí los evaluadores no
 * crean ni cambian evaluaciones (`SubmitReview` lanza `ReviewPeriodClosed`, también con una asignación ya
 * cargada en pantalla). Se ejecuta en la organización DEL EVENTO y exige `reviews.close_period`.
 *
 * El instante UTC del cierre queda en `events.review_period_closed_at` y quién lo cerró y cuándo, en la auditoría
 * (`review_period_closed`, con el motivo si se dio); no hay una columna del autor (DT-25). Cerrar un periodo
 * ya cerrado no cambia nada: conserva el instante original y no vuelve a auditar. `ReopenReviewPeriod` lo revierte.
 *
 * @throws AuthorizationException sin `reviews.close_period` en la organización del evento
 * @throws ValidationException si el motivo es inválido
 */
final class CloseReviewPeriod
{
    use AuthorizesInOrganization;

    /** @param  array<string, mixed>  $data  `reason` opcional */
    public function handle(User $actor, Event $event, array $data = []): Event
    {
        $organization = Organization::query()->findOrFail($event->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $event, $data): Event {
            $this->authorizeActor($actor, 'closePeriod', Review::class, $event);

            $input = Validator::make($data, (new CloseReviewPeriodRequest)->rules())->validate();

            return DB::transaction(function () use ($actor, $event, $input): Event {
                $current = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

                if ($current->review_period_closed_at !== null) {
                    return $current;
                }

                $current->forceFill(['review_period_closed_at' => Carbon::now('UTC')])->save();

                activity()
                    ->causedBy($actor)
                    ->performedOn($current)
                    ->event('review_period_closed')
                    ->withProperties([
                        'organization_id' => $current->organization_id,
                        'event_id' => $current->id,
                        'closed_at' => $current->review_period_closed_at?->toIso8601String(),
                        'reason' => $input['reason'] ?? null,
                    ])
                    ->log('review_period_closed');

                return $current;
            });
        });
    }
}
