<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Event;
use App\Models\ExtraFieldDefinition;
use App\Models\Lodging;
use App\Models\Sponsor;
use App\Support\Extras\ExtraFields;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;

/**
 * Preguntas del formulario de hospedajes y de patrocinadores (plan 030, DA-7): los campos que define el evento
 * (`ExtraFieldDefinition`) se agregan al formulario de cada registro, después de los campos fijos y en el orden de las
 * preguntas, un campo por pregunta según su tipo. Cada registro responde las suyas: el formulario de alta empieza vacío.
 * Las fotos y los PDF no se guardan en el disco del formulario: los sube la Action por su contenido. Al editar, un archivo
 * ya guardado se conserva si no se sube otro, y puede quitarse con su casilla.
 */
final class ExtraFieldsInput
{
    /**
     * Un campo por pregunta del evento y módulo, en su orden (ninguno si el evento no definió preguntas).
     *
     * @return list<CheckboxList|DatePicker|FileUpload|Select|Textarea|TextInput|Toggle>
     */
    public static function components(Event $event, string $scope): array
    {
        $components = [];

        foreach (ExtraFields::definitions($event, $scope) as $definition) {
            $id = $definition->id;
            $name = "extra_fields.{$id}";

            if ($definition->isFile()) {
                $isPhoto = $definition->type === ExtraFieldDefinition::TYPE_PHOTO;
                // No se guarda en el disco del formulario: la sube la Action, que decide por el contenido.
                $upload = FileUpload::make($name)
                    ->label($definition->label)
                    ->helperText(fn (?Model $record): string => self::hasFile($record, $id)
                        ? __('form_questions.file_current')
                        : __($isPhoto ? 'form_questions.photo_help' : 'form_questions.file_help'))
                    ->maxSize(2 * 1024)
                    ->storeFiles(false);
                $components[] = $isPhoto
                    ? $upload->image()->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                    : $upload->acceptedFileTypes(['application/pdf']);
                $components[] = Toggle::make("extra_remove.{$id}")
                    ->label(__('form_questions.remove_file', ['label' => $definition->label]))
                    ->visible(fn (?Model $record): bool => self::hasFile($record, $id) && ! $definition->required);

                continue;
            }

            $components[] = match ($definition->type) {
                ExtraFieldDefinition::TYPE_LONG_TEXT => Textarea::make($name)->rows(3)->maxLength(ExtraFields::MAX_LONG_TEXT),
                ExtraFieldDefinition::TYPE_NUMBER => TextInput::make($name)->numeric()
                    ->minValue(is_numeric($definition->config['min'] ?? null) ? $definition->config['min'] : null)
                    ->maxValue(is_numeric($definition->config['max'] ?? null) ? $definition->config['max'] : null),
                ExtraFieldDefinition::TYPE_DATE => DatePicker::make($name),
                ExtraFieldDefinition::TYPE_CHECKBOX => Toggle::make($name)->accepted($definition->required),
                ExtraFieldDefinition::TYPE_SINGLE_CHOICE => Select::make($name)->options($definition->options())->native(false),
                ExtraFieldDefinition::TYPE_MULTIPLE_CHOICE => CheckboxList::make($name)->options($definition->options()),
                default => TextInput::make($name)->maxLength(ExtraFields::MAX_SHORT_TEXT),
            };
            $field = $components[array_key_last($components)];
            $field->label($definition->label);

            // La casilla obligatoria se exige con `accepted`; las demás, con `required`. Los archivos los exige la Action.
            if ($definition->required && $definition->type !== ExtraFieldDefinition::TYPE_CHECKBOX) {
                $field->required();
            }
        }

        return $components;
    }

    /**
     * Respuestas guardadas, como estado inicial del formulario al editar (solo en la edición: el alta empieza vacía).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function fill(Model $record): array
    {
        return ['extra_fields' => ExtraFields::fill($record instanceof Lodging || $record instanceof Sponsor ? $record->extra_fields : null)];
    }

    /**
     * Estado del formulario → entrada de la Action: mapa pregunta => valor o archivo subido (temporal), y los archivos que
     * se quitan. Sin preguntas en el formulario no se envía nada y las respuestas guardadas no cambian.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function input(array $data): array
    {
        $raw = $data['extra_fields'] ?? null;
        $remove = $data['extra_remove'] ?? [];
        unset($data['extra_fields'], $data['extra_remove']);

        if (! is_array($raw)) {
            return $data;
        }

        $answers = [];

        foreach ($raw as $id => $value) {
            // Un campo de archivo trae una lista con el archivo temporal; una elección múltiple, una lista de valores.
            if (is_array($value) && collect($value)->contains(fn (mixed $item): bool => $item instanceof UploadedFile)) {
                $value = collect($value)->first(fn (mixed $item): bool => $item instanceof UploadedFile);
            }

            $answers[(string) $id] = $value;
        }

        $data['extra_fields'] = $answers;
        $data['extra_remove'] = is_array($remove) ? array_keys(array_filter($remove, static fn (mixed $on): bool => $on === true)) : [];

        return $data;
    }

    private static function hasFile(?Model $record, string $id): bool
    {
        $values = $record instanceof Lodging || $record instanceof Sponsor ? $record->extra_fields : null;

        return is_array($values) && is_array($values[$id] ?? null);
    }
}
