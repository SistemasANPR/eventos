<?php

declare(strict_types=1);

namespace App\Actions\Forms;

use App\Exceptions\Domain\FieldHasAnswers;
use App\Exceptions\Domain\FormChangeNotAllowed;
use App\Exceptions\Domain\FormVersionConflict;
use App\Models\Event;
use App\Models\FormDefinition;
use App\Models\Organization;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Forms\FormChangeRules;
use App\Support\Forms\FormDefinitionRules;
use App\Support\Forms\FormFieldsNormalizer;
use App\Support\Forms\LockedFormDefinition;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Edita el nombre y los campos de un formulario (plan 005, CA-005-7, CA-005-9 y
 * CA-005-18 a CA-005-23). Se ejecuta en la organización DEL FORMULARIO. Los campos
 * que ya existían conservan su clave; una clave que el cliente inventa se ignora.
 *
 * La definición se carga con bloqueo (`LockedFormDefinition`): si el cliente envía
 * `version` y ya no es la vigente, se rechaza para no pisar el cambio de otra persona.
 * Con respuestas recibidas se aplican las reglas de `FormChangeRules`. `version` sube
 * en 1 solo si algo cambió, y se audita con los valores anteriores y los nuevos.
 *
 * @throws AuthorizationException si quien edita no tiene `forms.manage` en la organización del formulario
 * @throws ValidationException si el nombre o los campos no cumplen las reglas del formulario
 * @throws FormVersionConflict si `version` no es la vigente
 * @throws FieldHasAnswers si se elimina un campo con respuestas
 * @throws FormChangeNotAllowed si el cambio contradice respuestas ya recibidas
 */
final class UpdateFormDefinition
{
    use AuthorizesInOrganization;

    public function __construct(
        private readonly FormFieldsNormalizer $normalizer,
        private readonly SanitizeFormText $sanitize,
        private readonly LockedFormDefinition $locked,
        private readonly FormChangeRules $changeRules,
    ) {}

    /** @param  array<string, mixed>  $data  `name`, `fields` y, para detectar ediciones simultáneas, `version` */
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

            return DB::transaction(function () use ($actor, $form, $data, $validated, $name): FormDefinition {
                $current = $this->locked->handle($form->id);

                if (isset($data['version']) && (int) $data['version'] !== $current->version) {
                    throw new FormVersionConflict;
                }

                $this->changeRules->assertAllowed($current, $validated['fields']);

                $previous = ['name' => $current->name, 'fields' => $current->fields];
                $fields = $this->normalizer->normalize($validated['fields'], $current->fields);

                if ($name === $current->name && self::canonical($fields) === self::canonical($current->fields)) {
                    return $current;
                }

                $current->forceFill(['name' => $name, 'fields' => $fields, 'version' => $current->version + 1])->save();

                activity()
                    ->causedBy($actor)
                    ->performedOn($current)
                    ->event('form_definition_updated')
                    ->withProperties([
                        'organization_id' => $current->organization_id,
                        'old' => $previous,
                        'new' => ['name' => $current->name, 'fields' => $current->fields],
                        'version' => $current->version,
                    ])
                    ->log('form_definition_updated');

                return $current;
            });
        });
    }

    /** PostgreSQL reordena las claves del jsonb: se compara sin importar el orden de las claves. */
    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(self::canonical(...), $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
