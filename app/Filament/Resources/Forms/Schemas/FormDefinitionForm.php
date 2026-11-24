<?php

declare(strict_types=1);

namespace App\Filament\Resources\Forms\Schemas;

use App\Actions\Forms\RetireFormField;
use App\Enums\FieldType;
use App\Filament\Resources\Forms\Pages\EditFormDefinition;
use App\Filament\Support\ActionErrors;
use App\Filament\Support\LocalizedFields;
use App\Models\Event;
use App\Models\FormDefinition;
use App\Support\Forms\FormDefinitionRules;
use App\Support\Forms\FormPreviewProps;
use App\Support\Forms\FormSettings;
use App\Support\Forms\ProposalFields;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Editor de un formulario de propuestas (plan 005, H-005-1). Es solo presentación:
 * la validación y las reglas de negocio viven en las Actions (`FormDefinitionRules`).
 * Las claves de los campos y los valores de las opciones van en campos ocultos para que
 * un campo conserve su identidad al editarse; el servidor decide cuáles son válidas.
 */
final class FormDefinitionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            // Columna izquierda: datos del formulario y, debajo, cómo se ve lo que se está editando.
            Group::make([
                Section::make(__('forms.sections.general'))
                    ->schema([
                        // El evento sale de la ruta (recurso anidado, plan 020) y no cambia nunca.
                        TextInput::make('name')
                            ->label(__('forms.fields.name'))
                            ->required()
                            ->maxLength(150),
                        TextInput::make('max_participants')
                            ->label(__('forms.fields.max_participants'))
                            ->helperText(__('forms.fields.max_participants_help'))
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(FormDefinitionRules::MAX_PARTICIPANTS)
                            ->placeholder('5')
                            // Al salir del campo se refresca «Así se ve» con el nuevo límite.
                            ->live(onBlur: true),
                        // Versión que se está editando: el servidor la compara para no pisar el cambio de otra persona.
                        Hidden::make('version'),
                    ]),
                Section::make(__('forms.sections.texts'))
                    ->description(__('forms.settings.texts_help'))
                    ->schema(fn ($livewire): array => LocalizedFields::for(
                        LocalizedFields::eventOf($livewire),
                        static fn (string $locale): array => [
                            TextInput::make("settings.title.{$locale}")
                                ->label(__("forms.settings.title_{$locale}"))
                                ->placeholder(__('forms.settings.title_default'))
                                ->maxLength(120),
                            TextInput::make("settings.extra_section_title.{$locale}")
                                ->label(__("forms.settings.extra_title_{$locale}"))
                                ->placeholder(__('forms.settings.extra_title_default'))
                                ->maxLength(120),
                        ],
                    )),
                Section::make(__('forms.sections.participants'))
                    ->description(__('forms.settings.participants_help'))
                    ->schema([
                        Select::make('settings.participants_position')
                            ->label(__('forms.settings.position'))
                            ->options([
                                FormSettings::POSITION_TOP => __('forms.settings.position_top'),
                                FormSettings::POSITION_BOTTOM => __('forms.settings.position_bottom'),
                            ])
                            ->default(FormSettings::POSITION_TOP)
                            ->selectablePlaceholder(false),
                        ...self::participantQuestions(),
                    ]),
                Section::make(__('forms.sections.live_preview'))
                    ->schema([
                        View::make('filament.forms.live-preview')
                            ->viewData(fn (Get $get, $livewire): array => [
                                'props' => app(FormPreviewProps::class)->participants(
                                    LocalizedFields::eventOf($livewire),
                                    $get('max_participants'),
                                    $get('settings'),
                                ),
                            ]),
                    ]),
            ]),
            Section::make(__('forms.sections.fields'))
                ->description(__('forms.fields.fields_help'))
                ->schema([
                    Repeater::make('fields')
                        ->label(__('forms.fields.fields'))
                        ->hiddenLabel()
                        ->addActionLabel(__('forms.fields.add_field'))
                        ->maxItems(FormDefinitionRules::MAX_FIELDS)
                        // Un formulario nuevo ya trae el título y el tema de la propuesta.
                        ->default(fn ($livewire): array => ProposalFields::defaults(LocalizedFields::eventOf($livewire)))
                        ->collapsible()
                        // Orden con flechas además de arrastrar: se puede reordenar con teclado (CA-005-11).
                        ->reorderableWithButtons()
                        ->itemLabel(fn (array $state): string => self::itemLabel($state))
                        ->extraItemActions([self::retireAction()])
                        ->schema(fn ($livewire): array => self::fieldInputs(LocalizedFields::eventOf($livewire))),
                ]),
        ]);
    }

    /**
     * Una fila por pregunta configurable del participante: mostrarla y, si se muestra, si es obligatoria. Las
     * preguntas fijas (nombre, apellido paterno, correo y país) no se pueden apagar y no aparecen aquí.
     *
     * @return list<Grid>
     */
    private static function participantQuestions(): array
    {
        $defaults = FormSettings::participantFields(null);
        $rows = [];

        foreach (FormSettings::PARTICIPANT_FIELDS as $key) {
            if (in_array($key, FormSettings::LOCKED, true)) {
                continue;
            }

            $rows[] = Grid::make(2)->schema([
                Toggle::make("settings.participant_fields.{$key}.enabled")
                    ->label(__("forms.settings.question.{$key}"))
                    ->helperText(__('forms.settings.show'))
                    ->default($defaults[$key]['enabled'])
                    ->live(),
                Toggle::make("settings.participant_fields.{$key}.required")
                    ->label(__('forms.settings.required'))
                    ->default($defaults[$key]['required'])
                    ->disabled(fn (Get $get): bool => $get("settings.participant_fields.{$key}.enabled") !== true)
                    ->live(),
            ]);
        }

        return $rows;
    }

    /** Retira el campo: deja de ofrecerse en nuevos envíos y conserva sus datos (CA-005-21). */
    private static function retireAction(): Action
    {
        return Action::make('retire')
            ->label(__('forms.actions.retire'))
            ->icon('heroicon-o-archive-box')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(__('forms.actions.retire_help'))
            ->visible(fn ($livewire): bool => $livewire instanceof EditFormDefinition)
            ->action(function (array $arguments, Repeater $component, $livewire): void {
                $key = $component->getRawItemState($arguments['item'])['key'] ?? null;

                $record = $livewire instanceof EditFormDefinition ? $livewire->getRecord() : null;

                if (! $record instanceof FormDefinition || ! is_string($key) || $key === '') {
                    Notification::make()->warning()->title(__('forms.actions.retire_save_first'))->send();

                    return;
                }

                try {
                    app(RetireFormField::class)->handle(ActionErrors::actor(), $record, $key);
                } catch (ValidationException $exception) {
                    Notification::make()->danger()->title(ActionErrors::summary($exception))->send();

                    return;
                }

                $livewire->refreshFormData(['fields', 'version']);
                Notification::make()->success()->title(__('forms.actions.retired'))->send();
            });
    }

    /** @return list<Hidden|Select|Toggle|TextInput|Textarea|Section|Repeater|CheckboxList> */
    private static function fieldInputs(Event $event): array
    {
        return [
            Hidden::make('key'),
            // Función del campo (título o tema de la propuesta): ver `ProposalFields`.
            Hidden::make('role'),
            Select::make('type')
                ->label(__('forms.fields.type'))
                ->options(self::typeOptions())
                ->required()
                ->live()
                // El título y el tema tienen un tipo fijo; sigue enviándose aunque el control esté apagado.
                ->disabled(fn (Get $get): bool => filled($get('role')))
                ->dehydrated()
                ->helperText(fn (Get $get): ?string => self::roleHelp($get('role'))),
            Toggle::make('required')
                ->label(__('forms.fields.required'))
                ->disabled(fn (Get $get): bool => filled($get('role')))
                ->dehydrated(),
            Toggle::make('identifiesAuthor')
                ->label(__('forms.fields.identifies_author'))
                ->helperText(__('forms.fields.identifies_author_help'))
                ->visible(fn (Get $get): bool => self::canIdentifyAuthor($get('type'))),
            Section::make(__('forms.fields.texts'))
                ->columns(1)
                ->schema(self::localizedTexts($event)),
            ...self::configInputs($event),
        ];
    }

    /** @return list<TextInput|Textarea> */
    private static function localizedTexts(Event $event): array
    {
        /** @var list<TextInput|Textarea> */
        return LocalizedFields::for($event, static fn (string $locale, bool $isDefault): array => [
            TextInput::make("label.{$locale}")
                ->label(__("forms.fields.label_{$locale}"))
                ->required($isDefault)
                ->maxLength(200),
            Textarea::make("help.{$locale}")
                ->label(__("forms.fields.help_{$locale}"))
                ->maxLength(500)
                ->rows(2),
        ]);
    }

    /** @return list<TextInput|Repeater|CheckboxList> */
    private static function configInputs(Event $event): array
    {
        return [
            TextInput::make('config.minLength')
                ->label(__('forms.fields.min_length'))
                ->numeric()->minValue(0)
                ->visible(fn (Get $get): bool => self::isText($get('type'))),
            TextInput::make('config.maxLength')
                ->label(__('forms.fields.max_length'))
                ->numeric()->minValue(1)
                ->visible(fn (Get $get): bool => self::isText($get('type'))),
            TextInput::make('config.min')
                ->label(__('forms.fields.min'))
                ->numeric()
                ->visible(fn (Get $get): bool => $get('type') === FieldType::Number->value),
            TextInput::make('config.max')
                ->label(__('forms.fields.max'))
                ->numeric()
                ->visible(fn (Get $get): bool => $get('type') === FieldType::Number->value),
            Repeater::make('config.options')
                ->label(__('forms.fields.options'))
                ->addActionLabel(__('forms.fields.add_option'))
                ->maxItems(FormDefinitionRules::MAX_OPTIONS)
                ->schema([
                    Hidden::make('value'),
                    ...self::optionLabels($event),
                ])
                // Las opciones del tema son los ejes del evento: no se escriben aquí.
                ->visible(fn (Get $get): bool => self::hasOptions($get('type')) && $get('role') !== ProposalFields::ROLE_TRACK),
            TextInput::make('config.maxSelections')
                ->label(__('forms.fields.max_selections'))
                ->numeric()->minValue(1)
                ->visible(fn (Get $get): bool => $get('type') === FieldType::MultipleChoice->value),
            CheckboxList::make('config.accept')
                ->label(__('forms.fields.accept'))
                ->options(array_combine(FormDefinitionRules::FILE_EXTENSIONS, FormDefinitionRules::FILE_EXTENSIONS))
                ->visible(fn (Get $get): bool => $get('type') === FieldType::File->value),
            TextInput::make('config.maxFiles')
                ->label(__('forms.fields.max_files'))
                ->numeric()->minValue(1)->maxValue(10)
                ->visible(fn (Get $get): bool => $get('type') === FieldType::File->value),
        ];
    }

    /** @return list<TextInput> */
    private static function optionLabels(Event $event): array
    {
        /** @var list<TextInput> */
        return LocalizedFields::for($event, static fn (string $locale, bool $isDefault): TextInput => TextInput::make("label.{$locale}")
            ->label(__("forms.fields.option_label_{$locale}"))
            ->required($isDefault)
            ->maxLength(200));
    }

    /** @return array<string, string> */
    private static function typeOptions(): array
    {
        $options = [];

        foreach (FieldType::cases() as $type) {
            $options[$type->value] = __("forms.types.{$type->value}");
        }

        return $options;
    }

    /** @param  array<string, mixed>  $state */
    private static function itemLabel(array $state): string
    {
        $label = $state['label'] ?? null;
        $text = is_array($label) ? (string) ($label[array_key_first($label)] ?? '') : '';
        $type = is_string($state['type'] ?? null) ? FieldType::tryFrom($state['type']) : null;

        $title = $text !== '' ? $text : __('forms.fields.untitled');
        $title .= ($state['retiredAt'] ?? null) !== null ? ' · '.__('forms.actions.retired_label') : '';

        return $type === null ? $title : $title.' · '.__("forms.types.{$type->value}");
    }

    private static function roleHelp(mixed $role): ?string
    {
        return is_string($role) && in_array($role, ProposalFields::ROLES, true) ? __("forms.fields.role_{$role}_help") : null;
    }

    private static function isText(mixed $type): bool
    {
        return $type === FieldType::ShortText->value || $type === FieldType::LongText->value;
    }

    private static function hasOptions(mixed $type): bool
    {
        return is_string($type) && FieldType::tryFrom($type)?->hasOptions() === true;
    }

    private static function canIdentifyAuthor(mixed $type): bool
    {
        return is_string($type) && FieldType::tryFrom($type)?->canIdentifyAuthor() === true;
    }
}
