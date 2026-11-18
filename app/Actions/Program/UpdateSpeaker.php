<?php

declare(strict_types=1);

namespace App\Actions\Program;

use App\Http\Requests\Program\UpdateSpeakerRequest;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Speaker;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Program\SpeakerAttributes;
use App\Support\Program\StoresSpeakerPhoto;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Edita la ficha de un ponente (plan 021, CA-021-1, CA-021-2, CA-021-3). Solo cambia las claves que
 * llegan en `$data`; el resto conserva su valor, y la validación corre sobre la ficha ya combinada
 * (nombre y apellido siguen siendo obligatorios). `photo` reemplaza la fotografía y `remove_photo`
 * la quita; `activity_ids`, si viene, deja al ponente exactamente en esas actividades del evento
 * (`SyncSpeakerActivities`, misma transacción); el archivo anterior se borra DESPUÉS de confirmar la transacción. `is_published` publica
 * o despublica; al publicar se limpia la marca «oculto por reversa». Se ejecuta en la organización
 * DEL PONENTE, en una transacción, y audita qué campos cambiaron (nombres de campo, sin los valores).
 *
 * @throws AuthorizationException si quien edita no tiene `speakers.manage` en la organización del ponente
 * @throws ValidationException si los datos no cumplen `UpdateSpeakerRequest` o la fotografía no es aceptable
 */
final class UpdateSpeaker
{
    use AuthorizesInOrganization, StoresSpeakerPhoto;

    /** Campos de la ficha que se auditan por nombre. */
    private const AUDITED = ['first_name', 'last_name', 'email', 'country', 'company', 'job_title', 'tagline', 'bio', 'social_links', 'is_published'];

    /** @param  array<string, mixed>  $data */
    public function handle(User $actor, Speaker $speaker, array $data): Speaker
    {
        $organization = Organization::query()->findOrFail($speaker->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $speaker, $data): Speaker {
            $this->authorizeActor($actor, 'update', $speaker);

            $event = Event::query()->findOrFail($speaker->event_id);
            $storedPhoto = null;
            $replacedPhoto = null;

            try {
                $speaker = DB::transaction(function () use ($actor, $speaker, $event, $data, &$storedPhoto, &$replacedPhoto): Speaker {
                    // Serializa las escrituras de ponentes del evento: correo único y reemplazo de fotografía sin carreras.
                    Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
                    $speaker->refresh();

                    $given = SpeakerAttributes::trimmed($data);
                    $validated = Validator::make(
                        [...$this->current($speaker), ...$given],
                        UpdateSpeakerRequest::rulesForUpdate($event, $speaker->id),
                    )->validate();

                    $attributes = SpeakerAttributes::forStorage(array_intersect_key($validated, $given));
                    $categoryIds = array_key_exists('category_ids', $attributes) ? $attributes['category_ids'] : null;
                    $photo = $attributes['photo'] ?? null;
                    $removePhoto = (bool) ($attributes['remove_photo'] ?? false);
                    $activityIds = array_key_exists('activity_ids', $attributes) ? array_values($attributes['activity_ids'] ?? []) : null;
                    unset($attributes['category_ids'], $attributes['photo'], $attributes['remove_photo'], $attributes['activity_ids']);

                    $photoChanged = false;

                    if ($photo instanceof UploadedFile) {
                        $storedPhoto = $this->storePhoto($photo, $event);
                        $replacedPhoto = $speaker->photo_path;
                        $attributes['photo_path'] = $storedPhoto;
                        $photoChanged = true;
                    } elseif ($removePhoto && $speaker->photo_path !== null) {
                        $replacedPhoto = $speaker->photo_path;
                        $attributes['photo_path'] = null;
                        $photoChanged = true;
                    }

                    if (($attributes['is_published'] ?? false) === true) {
                        $attributes['hidden_by_reversal_at'] = null;
                    }

                    $speaker->forceFill($attributes)->save();

                    $categoriesChanged = false;

                    if ($categoryIds !== null) {
                        $changes = $speaker->categories()->sync(SpeakerAttributes::categoryLinks($categoryIds, $speaker->organization_id, $event->id));
                        $categoriesChanged = $changes['attached'] !== [] || $changes['detached'] !== [];
                    }

                    // Las actividades solo cambian si vienen en los datos; la Action deja su propia auditoría.
                    if ($activityIds !== null) {
                        app(SyncSpeakerActivities::class)->handle($actor, $speaker, $activityIds);
                    }

                    $changed = array_values(array_intersect(self::AUDITED, array_keys($speaker->getChanges())));

                    if ($photoChanged) {
                        $changed[] = 'photo';
                    }

                    if ($categoriesChanged) {
                        $changed[] = 'categories';
                    }

                    activity()
                        ->causedBy($actor)
                        ->performedOn($speaker)
                        ->event('speaker_updated')
                        ->withProperties([
                            'organization_id' => $speaker->organization_id,
                            'event_id' => $event->id,
                            'speaker_id' => $speaker->id,
                            'changed' => $changed,
                        ])
                        ->log('speaker_updated');

                    return $speaker;
                });
            } catch (Throwable $exception) {
                if ($storedPhoto !== null) {
                    $this->discardPhoto($event, $storedPhoto);
                }

                throw $exception;
            }

            if ($replacedPhoto !== null) {
                $this->discardPhoto($event, $replacedPhoto);
            }

            return $speaker;
        });
    }

    /**
     * Valores actuales de la ficha, para validar la combinación con lo que llega.
     *
     * @return array<string, mixed>
     */
    private function current(Speaker $speaker): array
    {
        return [
            'first_name' => $speaker->first_name,
            'last_name' => $speaker->last_name,
            'email' => $speaker->email,
            'country' => $speaker->country,
            'company' => $speaker->company,
            'job_title' => $speaker->job_title,
            'tagline' => $speaker->tagline,
            'bio' => $speaker->bio,
            'social_links' => $speaker->social_links,
            'category_ids' => $speaker->categories()->pluck('speaker_categories.id')->all(),
            'is_published' => $speaker->is_published,
        ];
    }
}
