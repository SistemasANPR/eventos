<?php

declare(strict_types=1);

namespace App\Actions\Forms;

use App\Enums\FormDefinitionStatus;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\FormDefinition;
use App\Models\Organization;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Forms\FormDefinitionRules;
use App\Support\Forms\LockedFormDefinition;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Publica un formulario y lo asocia a una convocatoria de su mismo evento (plan 005,
 * CA-005-16 y CA-005-17). Sin al menos un campo vigente y válido se rechaza. Una
 * convocatoria de otra organización responde como inexistente (404); una de otro
 * evento de la misma organización se rechaza con un error de validación. Es
 * idempotente: volver a publicarlo no cambia `published_at`.
 *
 * @throws AuthorizationException si quien publica no tiene `forms.manage` en la organización del formulario
 * @throws ModelNotFoundException si la convocatoria es de otra organización
 * @throws ValidationException si la definición no es publicable o la convocatoria es de otro evento
 */
final class PublishFormDefinition
{
    use AuthorizesInOrganization;

    public function __construct(private readonly LockedFormDefinition $locked) {}

    public function handle(User $actor, FormDefinition $form, CallForSubmissions $call): FormDefinition
    {
        // Un formulario y una convocatoria de organizaciones distintas nunca se asocian: para quien
        // pregunta, uno de los dos no existe (404), sin revelar a cuál organización pertenece.
        if ($call->organization_id !== $form->organization_id) {
            throw (new ModelNotFoundException)->setModel(FormDefinition::class, [$form->id]);
        }

        $organization = Organization::query()->findOrFail($form->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $form, $call): FormDefinition {
            $this->authorizeActor($actor, 'update', $form);

            // Se relee con el contexto de la organización: una convocatoria ajena no se encuentra.
            $ownCall = CallForSubmissions::query()->findOrFail($call->id);

            if ($ownCall->event_id !== $form->event_id) {
                throw ValidationException::withMessages(['call' => __('forms.call_other_event')]);
            }

            return DB::transaction(function () use ($actor, $form, $ownCall): FormDefinition {
                $current = $this->locked->handle($form->id);

                $this->assertPublishable($current);

                $wasPublished = $current->status === FormDefinitionStatus::Published;
                $previousFormId = $ownCall->form_definition_id;

                $current->forceFill([
                    'status' => FormDefinitionStatus::Published,
                    'published_at' => $current->published_at ?? now(),
                ])->save();
                $ownCall->forceFill(['form_definition_id' => $current->id])->save();

                if (! $wasPublished || $previousFormId !== $current->id) {
                    activity()
                        ->causedBy($actor)
                        ->performedOn($current)
                        ->event('form_definition_published')
                        ->withProperties([
                            'organization_id' => $current->organization_id,
                            'call_id' => $ownCall->id,
                            'previous_form_definition_id' => $previousFormId,
                            'version' => $current->version,
                        ])
                        ->log('form_definition_published');
                }

                return $current;
            });
        });
    }

    /** @throws ValidationException */
    private function assertPublishable(FormDefinition $form): void
    {
        $active = array_filter($form->fields, static fn (array $field): bool => ($field['retiredAt'] ?? null) === null);

        if ($active === []) {
            throw ValidationException::withMessages(['fields' => __('forms.publish_needs_fields')]);
        }

        FormDefinitionRules::validate(
            ['name' => $form->name, 'fields' => array_values($form->fields)],
            Event::query()->findOrFail($form->event_id),
        );
    }
}
