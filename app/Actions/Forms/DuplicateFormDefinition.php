<?php

declare(strict_types=1);

namespace App\Actions\Forms;

use App\Models\Event;
use App\Models\FormDefinition;
use App\Models\Organization;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * Duplica un formulario propio (plan 005, H-005-5, CA-005-36 y CA-005-37). La copia queda
 * en borrador, sin respuestas ni asociación a una convocatoria, con los campos vigentes y
 * sus opciones; recibe claves nuevas (es independiente del original). Los campos retirados
 * no se copian: no tienen respuestas que conservar. Pasa por `CreateFormDefinition`.
 *
 * @throws ModelNotFoundException si quien duplica no es miembro activo de la organización del formulario (404)
 * @throws AuthorizationException si es miembro pero no tiene `forms.manage`
 * @throws ValidationException si la copia no cumple las reglas del formulario
 */
final class DuplicateFormDefinition
{
    use AuthorizesInOrganization;

    public function __construct(private readonly CreateFormDefinition $createForm) {}

    public function handle(User $actor, FormDefinition $source): FormDefinition
    {
        // El formulario de otra organización "no existe" para quien pregunta: 404, no 403.
        $isMember = $actor->organizations()
            ->whereKey($source->organization_id)
            ->wherePivot('status', 'active')
            ->exists();

        if (! $isMember) {
            throw (new ModelNotFoundException)->setModel(FormDefinition::class, [$source->id]);
        }

        $organization = Organization::query()->findOrFail($source->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $source): FormDefinition {
            $this->authorizeActor($actor, 'view', $source);

            $event = Event::query()->findOrFail($source->event_id);
            $fields = array_values(array_filter(
                $source->fields,
                static fn (array $field): bool => ($field['retiredAt'] ?? null) === null,
            ));

            return $this->createForm->handle($actor, $event, [
                'name' => mb_substr($source->name, 0, 150 - mb_strlen(__('forms.copy_suffix'))).__('forms.copy_suffix'),
                'fields' => $fields,
            ]);
        });
    }
}
