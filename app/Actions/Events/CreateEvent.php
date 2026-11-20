<?php

declare(strict_types=1);

namespace App\Actions\Events;

use App\Actions\Calls\SeedDefaultSubmissionTypes;
use App\Actions\Landings\CreateEventLanding;
use App\Actions\Program\DeleteProgramImage;
use App\Enums\EventStatus;
use App\Http\Requests\Events\StoreEventRequest;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Events\EventAttributes;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Crea un evento en borrador dentro de una organización (plan 003, CA-003-1 a 4).
 * Las fechas llegan como hora local del evento y se guardan en UTC. Nace con
 * los tipos de propuesta por defecto (plan 004, DA-7) y con su landing, ya publicada con la plantilla
 * (plan 022, DA-6). Deja auditoría de quién lo creó. Con `logo` (opcional) guarda el logotipo con
 * `StoreEventLogo` (plan 027, CA-027-1 y 2); si algo falla después, el archivo se borra.
 *
 * @throws AuthorizationException si quien crea no tiene `events.manage` en la organización
 * @throws ValidationException si los datos no son válidos (no se guarda nada)
 */
final class CreateEvent
{
    use AuthorizesInOrganization;

    public function __construct(
        private readonly SeedDefaultSubmissionTypes $seedSubmissionTypes,
        private readonly CreateEventLanding $createLanding,
        private readonly StoreEventLogo $storeLogo,
        private readonly DeleteProgramImage $deleteImage,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function handle(User $actor, Organization $organization, array $data): Event
    {
        return CurrentOrganization::run($organization, function () use ($actor, $organization, $data): Event {
            $this->authorizeActor($actor, 'create', Event::class);

            /** @var list<string> $locales */
            $locales = (array) ($data['locales'] ?? []);
            $validated = Validator::make($data, StoreEventRequest::rulesFor($organization->id, $locales))->validate();

            $created = null;

            try {
                return DB::transaction(function () use ($actor, $validated, &$created): Event {
                    return $this->create($actor, $validated, $created);
                });
            } catch (Throwable $exception) {
                // La transacción deshizo el evento pero no el archivo del logotipo: se borra para no dejar huérfanos.
                if ($created instanceof Event && $created->logo_path !== null) {
                    $this->deleteImage->handle($created, $created->logo_path);
                }

                throw $exception;
            }
        });
    }

    /**
     * @param  array<string, mixed>  $validated
     *
     * @param-out Event $created
     */
    private function create(User $actor, array $validated, ?Event &$created): Event
    {
        $event = Event::query()->create([
            ...EventAttributes::fromValidated($validated),
            'status' => EventStatus::Draft,
        ]);
        $created = $event;

        if (($validated['logo'] ?? null) instanceof UploadedFile) {
            $this->storeLogo->handle($actor, $event, $validated['logo']);
        }

        $this->seedSubmissionTypes->handle($event);

        // La landing nace con el evento, con la plantilla ya publicada (plan 022, DA-6): si falla, no queda el evento a medias.
        $this->createLanding->handle($actor, $event, publish: true);

        activity()
            ->causedBy($actor)
            ->performedOn($event)
            ->event('event_created')
            ->withProperties(['organization_id' => $event->organization_id])
            ->log('event_created');

        return $event;
    }
}
