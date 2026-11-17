<?php

declare(strict_types=1);

namespace App\Actions\Program;

use App\Enums\ProgramCategoryKind;
use App\Http\Requests\Program\StoreProgramCategoryRequest;
use App\Models\ActivityCategory;
use App\Models\Event;
use App\Models\Organization;
use App\Models\SpeakerCategory;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Agrega una categoría de ponentes o de actividades a un evento (plan 021, DA-7, CA-021-6): queda al
 * final de su catálogo y solo existe en ese evento. Se ejecuta en la organización DEL EVENTO y deja
 * auditoría. El nombre se compara sin distinguir mayúsculas ni espacios sobrantes.
 *
 * @throws AuthorizationException si quien crea no tiene `speakers.manage` (ponentes) o `activities.manage` (actividades) en la organización del evento
 * @throws ValidationException si el nombre falta, es muy largo o ya existe en el evento
 */
final class CreateProgramCategory
{
    use AuthorizesInOrganization;

    /** @param  array<string, mixed>  $data */
    public function handle(User $actor, Event $event, ProgramCategoryKind $kind, array $data): SpeakerCategory|ActivityCategory
    {
        $organization = Organization::query()->findOrFail($event->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $event, $kind, $data): SpeakerCategory|ActivityCategory {
            $this->authorizeActor($actor, 'create', $kind->modelClass(), $event);

            return DB::transaction(function () use ($actor, $event, $kind, $data): SpeakerCategory|ActivityCategory {
                // Se bloquea el evento para que dos altas simultáneas no tomen la misma posición ni el mismo nombre.
                Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

                $validated = Validator::make(
                    ['name' => self::trimmed($data['name'] ?? null)],
                    StoreProgramCategoryRequest::rulesFor($event, $kind),
                )->validate();

                $model = $kind->modelClass();
                $last = $model::query()->where('event_id', $event->id)->max('position');

                $category = new $model;
                $category->event_id = $event->id;
                $category->forceFill(['name' => $validated['name'], 'position' => (int) $last + 1])->save();

                activity()
                    ->causedBy($actor)
                    ->performedOn($category)
                    ->event("{$kind->auditPrefix()}_created")
                    ->withProperties([
                        'organization_id' => $category->organization_id,
                        'event_id' => $event->id,
                        'name' => $category->name,
                    ])
                    ->log("{$kind->auditPrefix()}_created");

                return $category;
            });
        });
    }

    private static function trimmed(mixed $name): mixed
    {
        return is_string($name) ? trim($name) : $name;
    }
}
