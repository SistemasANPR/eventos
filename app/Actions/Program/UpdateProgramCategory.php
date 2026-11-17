<?php

declare(strict_types=1);

namespace App\Actions\Program;

use App\Enums\ProgramCategoryKind;
use App\Http\Requests\Program\UpdateProgramCategoryRequest;
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
 * Cambia el nombre de una categoría de ponentes o de actividades (plan 021, DA-7, CA-021-6). Se
 * ejecuta en la organización DE LA CATEGORÍA y deja auditoría con el nombre anterior y el nuevo.
 *
 * @throws AuthorizationException si quien edita no tiene `speakers.manage` (ponentes) o `activities.manage` (actividades) en la organización de la categoría
 * @throws ValidationException si el nombre falta, es muy largo o ya lo tiene otra categoría del evento
 */
final class UpdateProgramCategory
{
    use AuthorizesInOrganization;

    /** @param  array<string, mixed>  $data */
    public function handle(User $actor, SpeakerCategory|ActivityCategory $category, array $data): SpeakerCategory|ActivityCategory
    {
        $organization = Organization::query()->findOrFail($category->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $category, $data): SpeakerCategory|ActivityCategory {
            $this->authorizeActor($actor, 'update', $category);

            $kind = ProgramCategoryKind::of($category);
            $event = Event::query()->findOrFail($category->event_id);
            $name = $data['name'] ?? null;

            $validated = Validator::make(
                ['name' => is_string($name) ? trim($name) : $name],
                UpdateProgramCategoryRequest::rulesFor($event, $kind, $category->id),
            )->validate();

            return DB::transaction(function () use ($actor, $category, $kind, $validated): SpeakerCategory|ActivityCategory {
                $previous = $category->name;

                $category->forceFill(['name' => $validated['name']])->save();

                activity()
                    ->causedBy($actor)
                    ->performedOn($category)
                    ->event("{$kind->auditPrefix()}_updated")
                    ->withProperties([
                        'organization_id' => $category->organization_id,
                        'event_id' => $category->event_id,
                        'old' => ['name' => $previous],
                        'new' => ['name' => $category->name],
                    ])
                    ->log("{$kind->auditPrefix()}_updated");

                return $category;
            });
        });
    }
}
