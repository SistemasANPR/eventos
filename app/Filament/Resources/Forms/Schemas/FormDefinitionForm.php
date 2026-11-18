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
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
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
            Section::make(__('forms.sections.general'))
                ->schema([
                    // El evento sale de la ruta (recurso anidado, plan 020) y no cambia nunca.
                    TextInput::make('name')
                        ->label(__('forms.fields.name'))
                        ->required()
                        ->maxLength(150),
                    // Versión que se está editando: el servidor la compara para no pisar el cambio de otra persona.
                    Hidden::make('version'),
                ]),
            Section::make(__('forms.sections.fields'))
                ->description(__('forms.fields.fields_help'))
                ->schema([
                    Repeater::make('fields')
                        ->label(__('forms.fields.fields'))
                        ->hiddenLabel()
                        ->addActionLabel(__('forms.fields.add_field'))
                        ->maxItems(FormDefinitionRules::MAX_FIELDS)
                        ->collapsible()
                        // Orden con flechas además de arrastrar: se puede reordenar con teclado (CA-005-11).
                        ->reorderableWithButtons()
                        ->itemLabel(fn (array $state): string => self::itemLabel($state))
                        ->extraItemActions([self::retireAction()])
                        ->schema(fn ($livewire): array => self::fieldInputs(LocalizedFields::eventOf($livewire))),
                ]),
        ]);
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
            Select::make('type')
                ->label(__('forms.fields.type'))
                ->options(self::typeOptions())
                ->required()
                ->live(),
            Toggle::make('required')
                ->label(__('forms.fields.required')),
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
                ->visible(fn (Get $get): bool => self::hasOptions($get('type'))),
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
