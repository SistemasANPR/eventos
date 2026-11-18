<?php

declare(strict_types=1);

namespace App\Actions\Program;

use App\Enums\ProgramImageType;
use App\Http\Requests\Program\UpdateActivityRequest;
use App\Models\Event;
use App\Models\EventActivity;
use App\Models\Organization;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Program\ActivityWrites;
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
 * @throws ValidationException si falta un dato obligatorio, el fin es anterior al inicio, una categoría o un ponente no es del evento o un archivo no es aceptable
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

            try {
                $icon = $validated['icon'] ?? null;
                $image = $validated['image'] ?? null;

                $newIcon = $icon instanceof UploadedFile ? $stored[] = ActivityWrites::storeImage($icon, $event, ProgramImageType::Icon, 'icon') : null;
                $newImage = $image instanceof UploadedFile ? $stored[] = ActivityWrites::storeImage($image, $event, ProgramImageType::Image, 'image') : null;

                $replaced = DB::transaction(function () use ($actor, $activity, $validated, $newIcon, $newImage): array {
                    $current = EventActivity::query()->whereKey($activity->id)->lockForUpdate()->firstOrFail();
                    $old = [];

                    $attributes = [
                        'title' => $validated['title'],
                        'starts_on' => $validated['starts_on'],
                        'ends_on' => $validated['ends_on'],
                        'starts_at' => ActivityWrites::time($validated['starts_at']),
                        'ends_at' => ActivityWrites::time($validated['ends_at']),
                    ];

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
                        ])
                        ->log('activity_updated');

                    $activity->setRawAttributes($current->getAttributes(), true);

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

            return $activity;
        });
    }
}
