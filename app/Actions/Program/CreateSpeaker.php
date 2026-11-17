<?php

declare(strict_types=1);

namespace App\Actions\Program;

use App\Http\Requests\Program\StoreSpeakerRequest;
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
 * Da de alta un ponente en un evento (plan 021, CA-021-1, CA-021-2, CA-021-3). Solo nombre y apellido
 * son obligatorios; nace publicado salvo que se indique lo contrario. La biografía se guarda saneada;
 * la fotografía se guarda por su contenido en el disco `media`; las categorías son del catálogo del
 * evento. Se ejecuta en la organización DEL EVENTO, en una transacción, y deja auditoría con los
 * identificadores (sin datos personales). Si algo falla, no queda ningún archivo huérfano.
 *
 * Regla de duplicados: el correo es único en el evento sin distinguir mayúsculas entre las fichas no
 * borradas; una ficha eliminada libera su correo.
 *
 * @throws AuthorizationException si quien crea no tiene `speakers.manage` en la organización del evento
 * @throws ValidationException si los datos no cumplen `StoreSpeakerRequest` o la fotografía no es aceptable
 */
final class CreateSpeaker
{
    use AuthorizesInOrganization, StoresSpeakerPhoto;

    /** @param  array<string, mixed>  $data */
    public function handle(User $actor, Event $event, array $data): Speaker
    {
        $organization = Organization::query()->findOrFail($event->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $event, $data): Speaker {
            $this->authorizeActor($actor, 'create', Speaker::class, $event);

            $storedPhoto = null;

            try {
                return DB::transaction(function () use ($actor, $event, $data, &$storedPhoto): Speaker {
                    // Se bloquea el evento para que dos altas simultáneas no tomen el mismo correo.
                    Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

                    $validated = Validator::make(
                        SpeakerAttributes::trimmed($data),
                        StoreSpeakerRequest::rulesFor($event),
                    )->validate();

                    $attributes = SpeakerAttributes::forStorage($validated);
                    $categoryIds = $attributes['category_ids'] ?? [];
                    $photo = $validated['photo'] ?? null;
                    unset($attributes['category_ids'], $attributes['photo']);

                    if ($photo instanceof UploadedFile) {
                        $storedPhoto = $this->storePhoto($photo, $event);
                        $attributes['photo_path'] = $storedPhoto;
                    }

                    $speaker = new Speaker;
                    $speaker->event_id = $event->id;
                    $speaker->forceFill([
                        ...$attributes,
                        'social_links' => $attributes['social_links'] ?? [],
                        'is_published' => $attributes['is_published'] ?? true,
                    ])->save();

                    $speaker->categories()->sync(SpeakerAttributes::categoryLinks($categoryIds, $speaker->organization_id, $event->id));

                    activity()
                        ->causedBy($actor)
                        ->performedOn($speaker)
                        ->event('speaker_created')
                        ->withProperties([
                            'organization_id' => $speaker->organization_id,
                            'event_id' => $event->id,
                            'speaker_id' => $speaker->id,
                            'is_published' => $speaker->is_published,
                        ])
                        ->log('speaker_created');

                    return $speaker;
                });
            } catch (Throwable $exception) {
                if ($storedPhoto !== null) {
                    $this->discardPhoto($event, $storedPhoto);
                }

                throw $exception;
            }
        });
    }
}
