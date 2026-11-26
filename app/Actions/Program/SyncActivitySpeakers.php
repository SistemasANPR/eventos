<?php

declare(strict_types=1);

namespace App\Actions\Program;

use App\Http\Requests\Program\StoreActivityRequest;
use App\Models\Event;
use App\Models\EventActivity;
use App\Models\Organization;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Program\DetectScheduleConflicts;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Deja exactamente estos ponentes en una actividad, en el orden dado (plan 021, DA-8, CA-021-5): ninguno
 * es válido y quita a todos. Solo se aceptan ponentes DEL MISMO EVENTO (no borrados); uno de otro evento
 * u organización, o un identificador mal formado, falla con error explícito y no cambia nada. Se ejecuta
 * en la organización DE LA ACTIVIDAD, dentro de una transacción (la base vuelve a exigir «mismo evento»
 * con claves compuestas) y audita con ids, sin texto libre, solo si algo cambió. Los ponentes que quedan
 * en otra actividad que se cruza con esta no impiden guardar: se devuelven como aviso en `scheduleWarnings`.
 *
 * @throws AuthorizationException si quien asigna no tiene `activities.manage` en la organización de la actividad
 * @throws ValidationException si algún ponente no es del evento de la actividad
 */
final class SyncActivitySpeakers
{
    use AuthorizesInOrganization;

    /** @param  list<string>  $speakerIds */
    public function handle(User $actor, EventActivity $activity, array $speakerIds): EventActivity
    {
        $organization = Organization::query()->findOrFail($activity->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $activity, $speakerIds): EventActivity {
            $this->authorizeActor($actor, 'update', $activity);

            $event = Event::query()->findOrFail($activity->event_id);
            $validated = Validator::make(['speaker_ids' => $speakerIds], StoreActivityRequest::speakerRules($event))->validate();
            $ids = array_values(array_unique(array_map(strtolower(...), $validated['speaker_ids'] ?? [])));

            DB::transaction(function () use ($actor, $activity, $ids): void {
                // Serializa las asignaciones de la actividad: el antes y el después no se cruzan.
                EventActivity::query()->whereKey($activity->id)->lockForUpdate()->firstOrFail();

                /** @var list<string> $before */
                $before = $activity->speakers()->pluck('speakers.id')->all();

                if ($before === $ids) {
                    return;
                }

                $links = [];

                foreach ($ids as $position => $id) {
                    $links[$id] = ['organization_id' => $activity->organization_id, 'event_id' => $activity->event_id, 'position' => $position];
                }

                $activity->speakers()->sync($links);

                activity()
                    ->causedBy($actor)
                    ->performedOn($activity)
                    ->event('activity_speakers_synced')
                    ->withProperties([
                        'organization_id' => $activity->organization_id,
                        'event_id' => $activity->event_id,
                        'attached' => array_values(array_diff($ids, $before)),
                        'detached' => array_values(array_diff($before, $ids)),
                    ])
                    ->log('activity_speakers_synced');
            });

            // Un ponente en dos actividades que se cruzan se guarda igual, con aviso (plan 031, CA-031-15). Se lee la fila vigente.
            $activity->scheduleWarnings = DetectScheduleConflicts::speakerClashes(EventActivity::query()->findOrFail($activity->id));

            return $activity;
        });
    }
}
