<?php

declare(strict_types=1);

namespace App\Actions\Program;

use App\Http\Requests\Program\StoreSpeakerRequest;
use App\Models\Event;
use App\Models\EventActivity;
use App\Models\Organization;
use App\Models\Speaker;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * La misma relación que `SyncActivitySpeakers`, vista desde la ficha del ponente (plan 021, DA-8,
 * CA-021-5): deja al ponente exactamente en estas actividades de su evento. En las actividades donde ya
 * estaba conserva su lugar entre los ponentes; en las nuevas entra al final. Cambiar la asignación de una
 * actividad exige también `activities.manage`, además de `speakers.manage` sobre el ponente. Una
 * actividad de otro evento u organización, o un identificador mal formado, falla con error explícito y
 * no cambia nada. Se ejecuta en la organización DEL PONENTE, en una transacción, y audita con ids.
 *
 * @throws AuthorizationException si quien asigna no tiene `speakers.manage` y `activities.manage` en la organización del ponente
 * @throws ValidationException si alguna actividad no es del evento del ponente
 */
final class SyncSpeakerActivities
{
    use AuthorizesInOrganization;

    /** @param  list<string>  $activityIds */
    public function handle(User $actor, Speaker $speaker, array $activityIds): Speaker
    {
        $organization = Organization::query()->findOrFail($speaker->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $speaker, $activityIds): Speaker {
            $this->authorizeActor($actor, 'update', $speaker);

            $event = Event::query()->findOrFail($speaker->event_id);
            $validated = Validator::make(['activity_ids' => $activityIds], StoreSpeakerRequest::activityRules($event))->validate();
            $ids = array_values(array_unique(array_map(strtolower(...), $validated['activity_ids'] ?? [])));

            DB::transaction(function () use ($actor, $speaker, $ids): void {
                Speaker::query()->whereKey($speaker->id)->lockForUpdate()->firstOrFail();

                /** @var list<string> $before */
                $before = $speaker->activities()->pluck('event_activities.id')->all();
                $attached = array_values(array_diff($ids, $before));
                $detached = array_values(array_diff($before, $ids));

                if ($attached === [] && $detached === []) {
                    return;
                }

                // Cambiar quién participa cambia la actividad: se exige permiso sobre cada una que cambia.
                $changing = EventActivity::query()->whereKey([...$attached, ...$detached])->get()->keyBy('id');

                foreach ($changing as $activity) {
                    $this->authorizeActor($actor, 'update', $activity);
                }

                $speaker->activities()->detach($detached);

                foreach ($attached as $activityId) {
                    $last = $changing->get($activityId)?->speakers()->max('activity_speaker.position');

                    $speaker->activities()->attach($activityId, [
                        'organization_id' => $speaker->organization_id,
                        'event_id' => $speaker->event_id,
                        'position' => $last === null ? 0 : (int) $last + 1,
                    ]);
                }

                activity()
                    ->causedBy($actor)
                    ->performedOn($speaker)
                    ->event('speaker_activities_synced')
                    ->withProperties([
                        'organization_id' => $speaker->organization_id,
                        'event_id' => $speaker->event_id,
                        'speaker_id' => $speaker->id,
                        'attached' => $attached,
                        'detached' => $detached,
                    ])
                    ->log('speaker_activities_synced');
            });

            return $speaker;
        });
    }
}
