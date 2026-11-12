<?php

declare(strict_types=1);

namespace App\Actions\Forms;

use App\Models\Event;
use App\Models\FormDefinition;
use App\Models\Organization;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Forms\FormDefinitionRules;
use App\Support\Forms\FormFieldsNormalizer;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Edita el nombre y los campos de un formulario (plan 005, H-005-1, CA-005-7 y
 * CA-005-9). Se ejecuta en la organización DEL FORMULARIO. Los campos que ya
 * existían conservan su clave; una clave que el cliente inventa se ignora. Deja
 * auditoría con los valores anteriores y los nuevos solo si algo cambió.
 *
 * Las reglas de cambio con respuestas y el número de versión llegan con H-005-3.
 *
 * @throws AuthorizationException si quien edita no tiene `forms.manage` en la organización del formulario
 * @throws ValidationException si el nombre o los campos no cumplen las reglas del formulario
 */
final class UpdateFormDefinition
{
    use AuthorizesInOrganization;

    public function __construct(
        private readonly FormFieldsNormalizer $normalizer,
        private readonly SanitizeFormText $sanitize,
    ) {}

    /** @param  array<string, mixed>  $data  `name` y `fields` */
    public function handle(User $actor, FormDefinition $form, array $data): FormDefinition
    {
        $organization = Organization::query()->findOrFail($form->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $form, $data): FormDefinition {
            $this->authorizeActor($actor, 'update', $form);

            $event = Event::query()->findOrFail($form->event_id);
            $validated = FormDefinitionRules::validate($data, $event);
            $name = $this->sanitize->handle($validated['name']);

            if ($name === '') {
                throw ValidationException::withMessages(['name' => __('forms.name_required')]);
            }

            $fields = $this->normalizer->normalize($validated['fields'], $form->fields);

            return DB::transaction(function () use ($actor, $form, $name, $fields): FormDefinition {
                $previous = ['name' => $form->name, 'fields' => $form->fields];

                $form->forceFill(['name' => $name, 'fields' => $fields]);

                if (! $form->isDirty()) {
                    return $form;
                }

                $form->save();

                activity()
                    ->causedBy($actor)
                    ->performedOn($form)
                    ->event('form_definition_updated')
                    ->withProperties([
                        'organization_id' => $form->organization_id,
                        'old' => $previous,
                        'new' => ['name' => $form->name, 'fields' => $form->fields],
                    ])
                    ->log('form_definition_updated');

                return $form;
            });
        });
    }
}
