<?php

declare(strict_types=1);

namespace App\Actions\Forms;

use App\Enums\FormDefinitionStatus;
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
 * Crea un formulario de propuestas en borrador para un evento (plan 005, H-005-1,
 * CA-005-1 a CA-005-9). Se ejecuta en la organización DEL EVENTO. Cada campo recibe
 * una clave estable generada aquí, nunca por el cliente. Deja auditoría.
 *
 * @throws AuthorizationException si quien crea no tiene `forms.manage` en la organización del evento
 * @throws ValidationException si el nombre o los campos no cumplen las reglas del formulario
 */
final class CreateFormDefinition
{
    use AuthorizesInOrganization;

    public function __construct(
        private readonly FormFieldsNormalizer $normalizer,
        private readonly SanitizeFormText $sanitize,
    ) {}

    /** @param  array<string, mixed>  $data  `name` y `fields` */
    public function handle(User $actor, Event $event, array $data): FormDefinition
    {
        $organization = Organization::query()->findOrFail($event->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $event, $data): FormDefinition {
            $this->authorizeActor($actor, 'create', FormDefinition::class, $event);

            $validated = FormDefinitionRules::validate($data, $event);
            $name = $this->sanitize->handle($validated['name']);

            if ($name === '') {
                throw ValidationException::withMessages(['name' => __('forms.name_required')]);
            }

            $fields = $this->normalizer->normalize($validated['fields']);

            return DB::transaction(function () use ($actor, $event, $name, $fields): FormDefinition {
                $form = new FormDefinition;
                $form->event_id = $event->id;
                $form->forceFill([
                    'name' => $name,
                    'status' => FormDefinitionStatus::Draft,
                    'fields' => $fields,
                    'version' => 1,
                ])->save();

                activity()
                    ->causedBy($actor)
                    ->performedOn($form)
                    ->event('form_definition_created')
                    ->withProperties([
                        'organization_id' => $form->organization_id,
                        'new' => ['name' => $form->name, 'fields' => $form->fields],
                    ])
                    ->log('form_definition_created');

                return $form;
            });
        });
    }
}
