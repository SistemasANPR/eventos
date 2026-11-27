<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Actions\Extras\SaveExtraFieldDefinitions;
use App\Models\Event;
use App\Models\ExtraFieldDefinition;
use App\Support\Extras\ExtraFields;
use App\Support\Forms\FormDefinitionRules;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Botón «Preguntas del formulario» de Hospedaje y de Patrocinadores (plan 030, DA-7): abre una ventana, parecida al
 * constructor de formularios de la convocatoria, con la lista de preguntas del evento (título, tipo, obligatoria y, según el
 * tipo, sus opciones o su rango), donde se agregan, reordenan (arrastrando o con las flechas) y quitan. Quitar una pregunta
 * pide confirmación y dice cuántas respuestas se perderán; al guardar se borran sus respuestas y archivos en todos los
 * registros. Delgado: la regla vive en `SaveExtraFieldDefinitions`.
 */
final class ExtraFieldDefinitionsAction
{
    /** @param  Closure(): Event  $event */
    public static function make(string $scope, Closure $event): Action
    {
        return Action::make('extraFields')
            ->label(__('form_questions.button'))
            ->icon(Heroicon::OutlinedQuestionMarkCircle)
            ->color('gray')
            ->outlined()
            ->badge(fn (): int => ExtraFieldDefinition::query()->where('event_id', $event()->id)->where('scope', $scope)->count())
            ->modalHeading(__('form_questions.modal_heading'))
            ->modalDescription(__("form_questions.modal_description_{$scope}"))
            ->modalSubmitActionLabel(__('form_questions.save'))
            ->modalWidth('3xl')
            ->visible(fn (): bool => Gate::allows('manage', [ExtraFieldDefinition::class, $event(), $scope]))
            ->fillForm(fn (): array => [
                'questions' => array_map(
                    static fn (ExtraFieldDefinition $definition): array => [
                        'id' => $definition->id,
                        'label' => $definition->label,
                        'type' => $definition->type,
                        'required' => $definition->required,
                        'config' => $definition->config ?? [],
                    ],
                    ExtraFields::definitions($event(), $scope),
                ),
            ])
            ->schema([self::questions($scope, $event)])
            ->action(function (array $data, Schema $schema) use ($scope, $event): void {
                $items = [];

                foreach (is_array($data['questions'] ?? null) ? array_values($data['questions']) : [] as $item) {
                    $items[] = is_array($item) ? $item : [];
                }

                try {
                    app(SaveExtraFieldDefinitions::class)->handle(ActionErrors::actor(), $event(), $scope, $items);
                } catch (ValidationException $exception) {
                    throw ActionErrors::forForm($exception, $schema->getStatePath());
                }

                Notification::make()->title(__('form_questions.saved'))->success()->send();
            });
    }

    /** @param  Closure(): Event  $event */
    private static function questions(string $scope, Closure $event): Repeater
    {
        $isChoice = static fn (Get $get): bool => in_array($get('type'), [ExtraFieldDefinition::TYPE_SINGLE_CHOICE, ExtraFieldDefinition::TYPE_MULTIPLE_CHOICE], true);

        return Repeater::make('questions')
            ->label(__('form_questions.questions'))
            ->helperText(__('form_questions.help', ['max' => ExtraFields::MAX]))
            ->addActionLabel(__('form_questions.add'))
            ->maxItems(ExtraFields::MAX)
            ->reorderable()
            ->reorderableWithButtons()
            ->collapsible()
            ->default([])
            ->itemLabel(function (array $state): string {
                $label = is_string($state['label'] ?? null) ? trim($state['label']) : '';
                $type = is_string($state['type'] ?? null) && in_array($state['type'], ExtraFieldDefinition::TYPES, true) ? ' · '.__("form_questions.types.{$state['type']}") : '';

                return ($label !== '' ? $label : __('form_questions.untitled')).$type;
            })
            // Quitar una pregunta ya guardada borra sus respuestas: se pide confirmación y se dice cuántas se pierden.
            ->deleteAction(fn (Action $action): Action => $action
                ->requiresConfirmation()
                ->modalHeading(__('form_questions.delete_heading'))
                ->modalSubmitActionLabel(__('form_questions.delete_confirm'))
                ->modalDescription(function (array $arguments, Repeater $component) use ($scope, $event): string {
                    $id = $component->getRawItemState($arguments['item'])['id'] ?? null;

                    if (! is_string($id) || $id === '') {
                        return __('form_questions.delete_unsaved');
                    }

                    return __('form_questions.delete_warning', [
                        'count' => ExtraFields::answersCount($event(), $scope, $id),
                        'records' => __("form_questions.records_{$scope}"),
                    ]);
                }))
            ->schema([
                Hidden::make('id'),
                TextInput::make('label')->label(__('form_questions.label'))->required()->maxLength(120)->live(onBlur: true),
                Select::make('type')
                    ->label(__('form_questions.type'))
                    ->options(array_combine(ExtraFieldDefinition::TYPES, array_map(static fn (string $type): string => __("form_questions.types.{$type}"), ExtraFieldDefinition::TYPES)))
                    ->default(ExtraFieldDefinition::TYPE_SHORT_TEXT)
                    ->required()
                    ->native(false)
                    ->live()
                    // El tipo de una pregunta ya guardada no cambia: para otro tipo se agrega una pregunta nueva.
                    ->disabled(fn (Get $get): bool => filled($get('id')))
                    ->dehydrated()
                    ->helperText(fn (Get $get): ?string => filled($get('id')) ? __('form_questions.type_fixed') : null),
                Toggle::make('required')->label(__('form_questions.required'))->helperText(__('form_questions.required_help')),
                TextInput::make('config.min')
                    ->label(__('form_questions.min'))
                    ->numeric()
                    ->visible(fn (Get $get): bool => $get('type') === ExtraFieldDefinition::TYPE_NUMBER),
                TextInput::make('config.max')
                    ->label(__('form_questions.max'))
                    ->numeric()
                    ->visible(fn (Get $get): bool => $get('type') === ExtraFieldDefinition::TYPE_NUMBER),
                Repeater::make('config.options')
                    ->label(__('form_questions.options'))
                    ->addActionLabel(__('form_questions.add_option'))
                    ->maxItems(FormDefinitionRules::MAX_OPTIONS)
                    ->minItems(fn (Get $get): int => $isChoice($get) ? 1 : 0)
                    ->reorderableWithButtons()
                    ->default([])
                    ->schema([
                        Hidden::make('value'),
                        TextInput::make('label')->label(__('form_questions.option'))->required()->maxLength(200),
                    ])
                    ->visible($isChoice),
            ]);
    }
}
