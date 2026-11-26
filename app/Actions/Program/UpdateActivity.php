<?php

declare(strict_types=1);

namespace App\Actions\Program;

use App\Enums\ProgramImageType;
use App\Exceptions\Domain\ActivityRoomConflict;
use App\Http\Requests\Program\UpdateActivityRequest;
use App\Models\Event;
use App\Models\EventActivity;
use App\Models\Organization;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Program\ActivityWrites;
use App\Support\Program\DetectScheduleConflicts;
use App\Support\Program\ExternalDetails;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Edita una actividad desde el panel (plan 021, DA-4, CA-021-4). Nombre y las cuatro marcas de tiempo
 * son siempre obligatorios: guardar una actividad «sin programar» la programa. `summary`,
 * `description`, `category_ids` e `is_published` solo cambian si vienen en los datos. Un icono o imagen
 * nuevo reemplaza al anterior; `remove_icon` / `remove_image` los quitan. Los archivos viejos se borran
 * DESPUÉS de confirmar la transacción; si algo falla, se borran los recién subidos y el anterior queda.
 * Se ejecuta en la organización DE LA ACTIVIDAD y deja auditoría con los nombres de campos cambiados
 * (sin valores). Republicar a mano limpia la marca «oculto por reversa».
 *
 * `description` se guarda saneada (DA-16). `speaker_ids`, si viene, deja exactamente esos ponentes del
 * evento (lista vacía: ninguno) con `SyncActivitySpeakers`, en la misma transacción.
 *
 * @throws AuthorizationException si quien edita no tiene `activities.manage` en la organización de la actividad
 *                                `room_id`, si viene, cambia la sala (nulo la quita); si no viene se conserva, y el horario nuevo se comprueba
 *                                contra ella. Una sala ocupada a esa hora rechaza TODO el cambio con `ActivityRoomConflict` (plan 031,
 *                                CA-031-10 y 16); un ponente repetido en otra actividad que se cruza solo avisa, en `EventActivity::$scheduleWarnings`.
 * @throws ValidationException si falta un dato obligatorio, el fin es anterior al inicio, una categoría, un ponente o la sala no es del evento o un archivo no es aceptable
 * @throws ActivityRoomConflict si la sala ya está ocupada a esa hora
 */
final class UpdateActivity
{
    use AuthorizesInOrganization;

    /** @param  array<string, mixed>  $data */
    public function handle(User $actor, EventActivity $activity, array $data): EventActivity
    {
        $organization = Organization::query()->findOrFail($activity->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $activity, $data): EventActivity {
            $this->authorizeActor($actor, 'update', $activity);

            $event = Event::query()->findOrFail($activity->event_id);
            $validated = Validator::make(ActivityWrites::trimmed($data), UpdateActivityRequest::rulesFor($event))->validate();

            $stored = [];
            $warnings = [];
            $quotaWarning = null;

            try {
                $icon = $validated['icon'] ?? null;
                $image = $validated['image'] ?? null;

                $newIcon = $icon instanceof UploadedFile ? $stored[] = ActivityWrites::storeImage($icon, $event, ProgramImageType::Icon, 'icon') : null;
                $newImage = $image instanceof UploadedFile ? $stored[] = ActivityWrites::storeImage($image, $event, ProgramImageType::Image, 'image') : null;

                $replaced = DB::transaction(function () use ($actor, $activity, $validated, $newIcon, $newImage, &$warnings, &$quotaWarning): array {
                    // Primero el evento y luego la actividad (siempre en ese orden); la sala se comprueba antes de escribir (DA-10).
                    ActivityWrites::lockEvent($activity->event_id);
                    $current = EventActivity::query()->whereKey($activity->id)->lockForUpdate()->firstOrFail();
                    $old = [];

                    // Sin `room_id` en los datos la actividad conserva su sala, y el nuevo horario se comprueba contra ella.
                    $roomId = array_key_exists('room_id', $validated) ? $validated['room_id'] : $current->room_id;
                    ActivityWrites::assertRoomFree($current->event_id, $roomId, ActivityWrites::slot($validated), $current->id);

                    // Los datos del lugar externo solo viven con un lugar externo; si ya no lo es, se borran (CA-035-13 y 14).
                    $external = ExternalDetails::isExternal($roomId);
                    ExternalDetails::assertAllowed($validated, $external);

                    $attributes = [
                        'title' => $validated['title'],
                        'starts_on' => $validated['starts_on'],
                        'ends_on' => $validated['ends_on'],
                        'starts_at' => ActivityWrites::time($validated['starts_at']),
                        'ends_at' => ActivityWrites::time($validated['ends_at']),
                    ];

                    if (array_key_exists('room_id', $validated)) {
                        $attributes['room_id'] = $validated['room_id'];
                    }

                    foreach (['meeting_point', 'transport_info'] as $field) {
                        if (! $external) {
                            $attributes[$field] = null;
                        } elseif (array_key_exists($field, $validated)) {
                            $attributes[$field] = $validated[$field];
                        }
                    }

                    if (array_key_exists('quota', $validated)) {
                        $attributes['quota'] = $validated['quota'] === null ? null : (int) $validated['quota'];
                    }

                    if (array_key_exists('summary', $validated)) {
                        $attributes['summary'] = $validated['summary'];
                    }

                    if (array_key_exists('description', $validated)) {
                        $attributes['description'] = ActivityWrites::description($validated['description']);
                    }

                    foreach (['icon' => $newIcon, 'image' => $newImage] as $field => $newPath) {
                        $column = "{$field}_path";

                        if ($newPath !== null || ($validated["remove_{$field}"] ?? false)) {
                            $old[] = $current->{$column};
                            $attributes[$column] = $newPath;
                        }
                    }

                    if (array_key_exists('is_published', $validated)) {
                        $attributes['is_published'] = $validated['is_published'];

                        if ($validated['is_published']) {
                            $attributes['hidden_by_reversal_at'] = null;
                        }
                    }

                    $current->forceFill($attributes)->save();
                    $changed = array_values(array_diff(array_keys($current->getChanges()), ['updated_at']));

                    if (array_key_exists('category_ids', $validated)) {
                        $sync = $current->categories()->sync(array_fill_keys(
                            array_values($validated['category_ids'] ?? []),
                            ['organization_id' => $current->organization_id, 'event_id' => $current->event_id],
                        ));

                        if ($sync['attached'] !== [] || $sync['detached'] !== []) {
                            $changed[] = 'categories';
                        }
                    }

                    // Los responsables: con un lugar externo, exactamente los que vengan (ausente conserva); si no, ninguno.
                    $contacts = null;

                    if (! $external) {
                        $contacts = ExternalDetails::syncContacts($current, []);
                    } elseif (array_key_exists('contacts', $validated)) {
                        $contacts = ExternalDetails::syncContacts($current, array_values($validated['contacts'] ?? []));
                    }

                    if ($contacts !== null && $contacts['changed']) {
                        $changed[] = 'contacts';
                    }

                    // Los ponentes solo cambian si vienen en los datos; la Action deja su propia auditoría.
                    if (array_key_exists('speaker_ids', $validated)) {
                        app(SyncActivitySpeakers::class)->handle($actor, $current, array_values($validated['speaker_ids'] ?? []));
                    }

                    activity()
                        ->causedBy($actor)
                        ->performedOn($current)
                        ->event('activity_updated')
                        ->withProperties([
                            'organization_id' => $current->organization_id,
                            'event_id' => $current->event_id,
                            'changed' => $changed,
                            // Solo cuántos responsables, nunca sus datos (plan 035, CA-035-14).
                            ...($contacts !== null && $contacts['changed'] ? ['contacts' => ['before' => $contacts['before'], 'after' => $contacts['after']]] : []),
                        ])
                        ->log('activity_updated');

                    $activity->setRawAttributes($current->getAttributes(), true);
                    $warnings = DetectScheduleConflicts::speakerClashes($current);
                    $quotaWarning = ActivityWrites::quotaWarning($current->quota, $current->room_id);

                    return array_values(array_filter($old, is_string(...)));
                });
            } catch (Throwable $exception) {
                foreach ($stored as $path) {
                    app(DeleteProgramImage::class)->handle($event, $path);
                }

                throw $exception;
            }

            foreach ($replaced as $path) {
                app(DeleteProgramImage::class)->handle($event, $path);
            }

            $activity->scheduleWarnings = $warnings;
            $activity->quotaWarning = $quotaWarning;

            return $activity;
        });
    }
}
