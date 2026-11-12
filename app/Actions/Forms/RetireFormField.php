<?php

declare(strict_types=1);

namespace App\Actions\Forms;

use App\Models\FormDefinition;
use App\Models\Organization;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Forms\LockedFormDefinition;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Retira un campo (plan 005, CA-005-21): deja de ofrecerse en nuevos envíos, sus datos
 * ya guardados se conservan y no es obligatorio para nadie. Aumenta `version` y queda
 * auditado. Retirar un campo ya retirado no hace nada.
 *
 * @throws AuthorizationException si quien retira no tiene `forms.manage` en la organización del formulario
 * @throws ValidationException si el campo no existe en el formulario
 */
final class RetireFormField
{
    use AuthorizesInOrganization;

    public function __construct(private readonly LockedFormDefinition $locked) {}

    public function handle(User $actor, FormDefinition $form, string $fieldKey): FormDefinition
    {
        $organization = Organization::query()->findOrFail($form->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $form, $fieldKey): FormDefinition {
            $this->authorizeActor($actor, 'update', $form);

            return DB::transaction(function () use ($actor, $form, $fieldKey): FormDefinition {
                $current = $this->locked->handle($form->id);
                $fields = $current->fields;
                $index = array_search($fieldKey, array_column($fields, 'key'), true);

                if ($index === false) {
                    throw ValidationException::withMessages(['field' => __('forms.field_not_found')]);
                }

                if (($fields[$index]['retiredAt'] ?? null) !== null) {
                    return $current;
                }

                $fields[$index]['retiredAt'] = now()->utc()->toIso8601String();
                $fields[$index]['required'] = false;

                $current->forceFill(['fields' => $fields, 'version' => $current->version + 1])->save();

                activity()
                    ->causedBy($actor)
                    ->performedOn($current)
                    ->event('form_field_retired')
                    ->withProperties([
                        'organization_id' => $current->organization_id,
                        'field_key' => $fieldKey,
                        'version' => $current->version,
                    ])
                    ->log('form_field_retired');

                return $current;
            });
        });
    }
}
