<?php

declare(strict_types=1);

namespace App\Actions\Extras;

use App\Models\Event;
use App\Models\ExtraFieldDefinition;
use App\Models\Organization;
use App\Models\User;
use App\Support\Extras\ExtraFields;
use App\Support\Forms\FormDefinitionRules;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Guarda las preguntas del formulario de hospedajes o de patrocinadores de un evento (plan 030, DA-7, CA-030-18 a
 * CA-030-20). La lista que llega REEMPLAZA a la del evento y módulo (`scope`): una pregunta con el `id` de una existente
 * conserva su identidad (y sus respuestas) y toma el título y la posición nuevos; las que ya no vienen se eliminan y, con
 * ellas, sus respuestas en todos los registros del evento y las fotos de esas respuestas (se borran del disco DESPUÉS de
 * confirmar la transacción). Cambiar el tipo de una pregunta existente también descarta sus respuestas; quitar una opción
 * de una pregunta de elección descarta esa opción de las respuestas. Hasta `ExtraFields::MAX` preguntas de los tipos de
 * `ExtraFieldDefinition::TYPES`, cada una obligatoria o no; las de elección llevan sus opciones (hasta
 * `FormDefinitionRules::MAX_OPTIONS`) y las de número pueden llevar mínimo y máximo. Se ejecuta en la organización DEL EVENTO, en una transacción, y audita
 * con identificadores y conteos.
 *
 * @throws AuthorizationException si quien guarda no administra el módulo en la organización del evento
 * @throws ValidationException si el módulo, algún título o algún tipo no son válidos o hay demasiadas preguntas
 */
final class SaveExtraFieldDefinitions
{
    /**
     * @param  list<array<string, mixed>>  $items  `id` (opcional), `label`, `type`, `required` y `config` de cada pregunta, en su orden
     * @return list<ExtraFieldDefinition>
     */
    public function handle(User $actor, Event $event, string $scope, array $items): array
    {
        $organization = Organization::query()->findOrFail($event->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $event, $scope, $items): array {
            $actor->unsetRelation('roles')->unsetRelation('permissions');
            Gate::forUser($actor)->authorize('manage', [ExtraFieldDefinition::class, $event, $scope]);

            $items = $this->validated($scope, $items);
            $paths = [];

            $saved = DB::transaction(function () use ($actor, $event, $scope, $items, &$paths): array {
                // Se bloquea el evento para que dos guardados simultáneos no se pisen.
                Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

                $existing = ExtraFieldDefinition::query()->where('event_id', $event->id)->where('scope', $scope)->get()->keyBy('id');
                $kept = [];
                $cleared = [];
                $saved = [];

                foreach ($items as $index => $item) {
                    $id = is_string($item['id'] ?? null) ? $item['id'] : null;
                    $definition = $id !== null && ! isset($kept[$id]) ? $existing->get($id) : null;

                    if ($definition === null) {
                        $definition = new ExtraFieldDefinition;
                        $definition->event_id = $event->id;
                        $definition->scope = $scope;
                    } else {
                        $kept[$definition->id] = true;

                        // Otro tipo no puede reutilizar lo que se respondió con el anterior.
                        if ($definition->type !== $item['type']) {
                            $cleared[] = $definition->id;
                        }
                    }

                    $definition->forceFill([
                        'label' => $item['label'],
                        'type' => $item['type'],
                        'required' => $item['required'],
                        'config' => $item['config'],
                        'position' => $index + 1,
                    ])->save();
                    $kept[$definition->id] = true;
                    $saved[] = $definition;
                }

                $removed = $existing->keys()->reject(fn (string $id): bool => isset($kept[$id]))->values()->all();

                foreach ($removed as $id) {
                    $existing->get($id)?->delete();
                }

                $cleared = [...$cleared, ...$removed];
                $answers = $this->reconcileAnswers($event, $scope, $cleared, $saved, $paths);

                activity()
                    ->causedBy($actor)
                    ->performedOn($event)
                    ->event('extra_fields_saved')
                    ->withProperties([
                        'organization_id' => $event->organization_id,
                        'event_id' => $event->id,
                        'scope' => $scope,
                        'questions' => count($saved),
                        'removed' => count($removed),
                        'answers_cleared' => $answers,
                    ])
                    ->log('extra_fields_saved');

                return $saved;
            });

            ExtraFields::discard($event, $paths);

            return $saved;
        });
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array{id: ?string, label: string, type: string, required: bool, config: ?array<string, mixed>}>
     *
     * @throws ValidationException
     */
    private function validated(string $scope, array $items): array
    {
        $items = array_map(static function (mixed $item): mixed {
            if (is_array($item) && is_string($item['label'] ?? null)) {
                $item['label'] = trim($item['label']);
            }

            return $item;
        }, array_values($items));

        $validated = Validator::make(
            ['scope' => $scope, 'questions' => $items],
            [
                'scope' => ['required', 'in:'.implode(',', ExtraFieldDefinition::SCOPES)],
                'questions' => ['array', 'max:'.ExtraFields::MAX],
                'questions.*' => ['array'],
                'questions.*.id' => ['nullable', 'string', 'uuid'],
                'questions.*.label' => ['required', 'string', 'max:120'],
                'questions.*.type' => ['required', 'string', 'in:'.implode(',', ExtraFieldDefinition::TYPES)],
                'questions.*.required' => ['nullable', 'boolean'],
                'questions.*.config' => ['nullable', 'array'],
                'questions.*.config.min' => ['nullable', 'numeric'],
                'questions.*.config.max' => ['nullable', 'numeric'],
                'questions.*.config.options' => ['nullable', 'array', 'max:'.FormDefinitionRules::MAX_OPTIONS],
                'questions.*.config.options.*' => ['array'],
                'questions.*.config.options.*.value' => ['nullable', 'string', 'max:64'],
                'questions.*.config.options.*.label' => ['required', 'string', 'max:200'],
            ],
            [],
            [
                'questions' => __('form_questions.questions'),
                'questions.*.label' => __('form_questions.label'),
                'questions.*.type' => __('form_questions.type'),
                'questions.*.config.min' => __('form_questions.min'),
                'questions.*.config.max' => __('form_questions.max'),
                'questions.*.config.options' => __('form_questions.options'),
                'questions.*.config.options.*.label' => __('form_questions.option'),
            ],
        )->validate();

        $result = [];

        foreach ($validated['questions'] ?? [] as $index => $item) {
            $result[] = [
                'id' => is_string($item['id'] ?? null) && Str::isUuid($item['id']) ? $item['id'] : null,
                'label' => $item['label'],
                'type' => $item['type'],
                'required' => filter_var($item['required'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'config' => $this->config($item, $index),
            ];
        }

        return $result;
    }

    /**
     * La configuración que le corresponde al tipo (y solo esa): mínimo y máximo de un número, opciones de una elección.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     *
     * @throws ValidationException
     */
    private function config(array $item, int $index): ?array
    {
        $config = is_array($item['config'] ?? null) ? $item['config'] : [];

        if ($item['type'] === ExtraFieldDefinition::TYPE_NUMBER) {
            $min = is_numeric($config['min'] ?? null) ? $config['min'] + 0 : null;
            $max = is_numeric($config['max'] ?? null) ? $config['max'] + 0 : null;

            if ($min !== null && $max !== null && $min > $max) {
                throw ValidationException::withMessages(["questions.{$index}.config.max" => __('form_questions.errors.min_over_max')]);
            }

            return $min === null && $max === null ? null : array_filter(['min' => $min, 'max' => $max], static fn (mixed $value): bool => $value !== null);
        }

        if ($item['type'] !== ExtraFieldDefinition::TYPE_SINGLE_CHOICE && $item['type'] !== ExtraFieldDefinition::TYPE_MULTIPLE_CHOICE) {
            return null;
        }

        $options = [];
        $seen = [];

        foreach (is_array($config['options'] ?? null) ? array_values($config['options']) : [] as $option) {
            $value = is_string($option['value'] ?? null) && $option['value'] !== '' && ! isset($seen[$option['value']]) ? $option['value'] : 'o_'.Str::lower(Str::random(10));
            $seen[$value] = true;
            $options[] = ['value' => $value, 'label' => trim((string) $option['label'])];
        }

        if ($options === []) {
            throw ValidationException::withMessages(["questions.{$index}.config.options" => __('form_questions.errors.options_required')]);
        }

        return ['options' => $options];
    }

    /**
     * Pone al día las respuestas de los registros del evento y módulo con las preguntas ya guardadas: quita las de las
     * preguntas eliminadas o de tipo cambiado (`$cleared`, con sus archivos, que anota en `$paths`) y las opciones que ya
     * no existen en las preguntas de elección.
     *
     * @param  list<string>  $cleared  identificadores de preguntas cuyas respuestas se descartan
     * @param  list<ExtraFieldDefinition>  $saved  preguntas vigentes tras el guardado
     * @param  list<string>  $paths  (por referencia) archivos que quedan sin uso
     * @return int respuestas descartadas
     */
    private function reconcileAnswers(Event $event, string $scope, array $cleared, array $saved, array &$paths): int
    {
        $choices = [];

        foreach ($saved as $definition) {
            if ($definition->hasOptions()) {
                $choices[$definition->id] = $definition;
            }
        }

        if ($cleared === [] && $choices === []) {
            return 0;
        }

        $count = 0;

        foreach (ExtraFields::records($event, $scope) as $record) {
            $values = is_array($record->extra_fields) ? $record->extra_fields : [];
            $dropped = array_intersect_key($values, array_flip($cleared));
            $remaining = array_diff_key($values, $dropped);

            foreach ($choices as $id => $definition) {
                if (! array_key_exists($id, $remaining)) {
                    continue;
                }

                $valid = array_keys($definition->options());
                $kept = is_array($remaining[$id]) ? array_values(array_intersect($remaining[$id], $valid)) : (in_array($remaining[$id], $valid, true) ? $remaining[$id] : null);

                if ($kept === [] || $kept === null) {
                    unset($remaining[$id]);
                } else {
                    $remaining[$id] = $kept;
                }
            }

            if ($remaining === $values) {
                continue;
            }

            $count += count($values) - count($remaining);
            $paths = [...$paths, ...ExtraFields::paths($dropped)];
            $record->forceFill(['extra_fields' => $remaining === [] ? null : $remaining])->save();
        }

        return $count;
    }
}
