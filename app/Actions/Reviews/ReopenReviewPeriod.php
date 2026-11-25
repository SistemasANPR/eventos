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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Reabre el periodo de evaluación de un evento (plan 011, H-011-6, CA-011-27): los evaluadores vuelven a poder
 * calificar y reevaluar. Se ejecuta en la organización DEL EVENTO y exige el mismo permiso que cerrarlo
 * (`reviews.close_period`). Queda auditado quién y cuándo (`review_period_reopened`, con el instante del cierre
 * anterior y el motivo). Reabrir un periodo que ya está abierto no cambia nada ni audita.
 *
 * @throws AuthorizationException sin `reviews.close_period` en la organización del evento
 * @throws ValidationException si el motivo es inválido
 */
final class ReopenReviewPeriod
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
                $closedAt = $current->review_period_closed_at;

                if ($closedAt === null) {
                    return $current;
                }

                $current->forceFill(['review_period_closed_at' => null])->save();

                activity()
                    ->causedBy($actor)
                    ->performedOn($current)
                    ->event('review_period_reopened')
                    ->withProperties([
                        'organization_id' => $current->organization_id,
                        'event_id' => $current->id,
                        'previously_closed_at' => $closedAt->toIso8601String(),
                        'reason' => $input['reason'] ?? null,
                    ])
                    ->log('review_period_reopened');

                return $current;
            });
        });
    }
}
