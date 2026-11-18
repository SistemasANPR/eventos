<?php

declare(strict_types=1);

namespace App\Actions\Program;

use App\Enums\ProgramCategoryKind;
use App\Models\ActivityCategory;
use App\Models\Organization;
use App\Models\SpeakerCategory;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Elimina una categoría de ponentes o de actividades (plan 021, DA-7, DA-13). Solo `org_admin`
 * (`speakers.delete` o `activities.delete`). Una categoría es solo una etiqueta: borrarla no borra
 * a los ponentes ni a las actividades que la tenían; sus vínculos se limpian en cascada (pivotes
 * de las fases F2 y F3). Se ejecuta en la organización DE LA CATEGORÍA y deja auditoría.
 *
 * @throws AuthorizationException si quien elimina no tiene el permiso de eliminar del catálogo en la organización de la categoría
 */
final class DeleteProgramCategory
{
    use AuthorizesInOrganization;

    public function handle(User $actor, SpeakerCategory|ActivityCategory $category): void
    {
        $organization = Organization::query()->findOrFail($category->organization_id);

        CurrentOrganization::run($organization, function () use ($actor, $category): void {
            $this->authorizeActor($actor, 'delete', $category);

            $kind = ProgramCategoryKind::of($category);

            DB::transaction(function () use ($actor, $category, $kind): void {
                $snapshot = $category->name;
                $category->delete();

                activity()
                    ->causedBy($actor)
                    ->performedOn($category)
                    ->event("{$kind->auditPrefix()}_deleted")
                    ->withProperties([
                        'organization_id' => $category->organization_id,
                        'event_id' => $category->event_id,
                        'old' => ['name' => $snapshot],
                    ])
                    ->log("{$kind->auditPrefix()}_deleted");
            });
        });
    }
}
