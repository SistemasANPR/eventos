<?php

declare(strict_types=1);

namespace App\Filament\Resources\Rubrics\Schemas;

use App\Enums\CriterionKind;
use App\Enums\RubricStatus;
use App\Filament\Resources\Rubrics\Pages\EditRubric;
use App\Filament\Support\LocalizedFields;
use App\Models\Event;
use App\Models\Rubric;
use App\Models\SubmissionType;
use App\Support\Reviews\AuthorIdentifyingFields;
use App\Support\Reviews\RubricUsageChecker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Editor de una rúbrica (plan 011, F4b, H-011-1). Es solo presentación: las reglas (peso mayor que cero,
 * escala, umbral de discrepancia) y la autorización viven en `SaveRubric` y `StoreRubricRequest`; sus
 * mensajes llegan junto a cada campo. El tipo de propuesta se elige al crear y no cambia después.
 */
final class RubricForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Callout::make(__('rubrics.versioning.notice'))
                ->description(__('rubrics.versioning.notice_detail'))
                ->info()
                ->visible(fn ($livewire): bool => self::willCreateNewVersion($livewire)),
            Section::make(__('rubrics.sections.general'))
                ->columns(2)
                ->schema([
                    Select::make('submission_type_id')
                        ->label(__('rubrics.fields.submission_type'))
                        ->options(fn ($livewire): array => self::typeOptions(LocalizedFields::eventOf($livewire), $livewire instanceof EditRubric))
                        ->required()
                        ->disabled(fn ($livewire): bool => $livewire instanceof EditRubric)
                        ->dehydrated()
                        ->columnSpanFull(),
                    TextInput::make('min_reviewers')
                        ->label(__('rubrics.fields.min_reviewers'))
                        ->helperText(__('rubrics.fields.min_reviewers_help'))
                        ->numeric()
                        ->integer()
                        ->default(2)
                        ->required(),
                    TextInput::make('discrepancy_threshold')
                        ->label(__('rubrics.fields.discrepancy_threshold'))
                        ->helperText(__('rubrics.fields.discrepancy_threshold_help'))
                        ->numeric()
                        ->integer()
                        ->default(2)
                        ->required(),
                    Toggle::make('blind_review')
                        ->label(__('rubrics.fields.blind_review'))
                        ->helperText(__('rubrics.fields.blind_review_help'))
                        ->live()
                        ->columnSpanFull(),
                    Callout::make(__('rubrics.blind.warning'))
                        ->description(fn ($livewire): string => self::blindDetail(LocalizedFields::eventOf($livewire)))
                        ->warning()
                        ->visible(fn (Get $get): bool => (bool) $get('blind_review'))
                        ->columnSpanFull(),
                ]),
            Section::make(__('rubrics.sections.criteria'))
                ->description(__('rubrics.fields.criteria_help'))
                ->schema([
                    Repeater::make('criteria')
                        ->label(__('rubrics.fields.criteria'))
                        ->hiddenLabel()
                        ->addActionLabel(__('rubrics.fields.add_criterion'))
                        ->minItems(1)
                        ->maxItems(50)
                        ->defaultItems(1)
                        ->collapsible()
                        // Orden con flechas además de arrastrar: se puede reordenar con teclado.
                        ->reorderableWithButtons()
                        ->itemLabel(fn (array $state): string => self::itemLabel($state))
                        ->schema(fn ($livewire): array => self::criterionInputs(LocalizedFields::eventOf($livewire))),
                ]),
        ]);
    }

    /** @return list<Component> */
    private static function criterionInputs(Event $event): array
    {
        return [
            ...LocalizedFields::for($event, static fn (string $locale, bool $isDefault): TextInput => TextInput::make("label.{$locale}")
                ->label(__("rubrics.fields.label_{$locale}"))
                ->required($isDefault)
                ->maxLength(200)),
            Select::make('kind')
                ->label(__('rubrics.fields.kind'))
                ->options(self::kindOptions())
                ->default(CriterionKind::Scale->value)
                ->required()
                ->live(),
            TextInput::make('weight')
                ->label(__('rubrics.fields.weight'))
                ->helperText(__('rubrics.fields.weight_help'))
                ->numeric()
                ->default(1)
                ->required(),
            TextInput::make('scale_min')
                ->label(__('rubrics.fields.scale_min'))
                ->numeric()
                ->integer()
                ->default(1)
                ->required()
                ->visible(fn (Get $get): bool => $get('kind') === CriterionKind::Scale->value),
            TextInput::make('scale_max')
                ->label(__('rubrics.fields.scale_max'))
                ->numeric()
                ->integer()
                ->default(5)
                ->required()
                ->visible(fn (Get $get): bool => $get('kind') === CriterionKind::Scale->value),
        ];
    }

    /** @return array<string, string> tipos del evento; al crear, sin los archivados */
    private static function typeOptions(Event $event, bool $includeArchived): array
    {
        $query = SubmissionType::query()->where('event_id', $event->id)->orderBy('position');

        if (! $includeArchived) {
            $query->active();
        }

        return $query->get()
            ->mapWithKeys(fn (SubmissionType $type): array => [$type->id => $type->label($event->default_locale)])
            ->all();
    }

    /** @return array<string, string> */
    private static function kindOptions(): array
    {
        $options = [];

        foreach (CriterionKind::cases() as $kind) {
            $options[$kind->value] = __("rubrics.kinds.{$kind->value}");
        }

        return $options;
    }

    /** Detalle de la advertencia del modo ciego: qué campos del formulario sí se ocultan (CA-011-21). */
    private static function blindDetail(Event $event): string
    {
        $labels = AuthorIdentifyingFields::labelsFor($event);

        return __('rubrics.blind.warning_detail').' '.($labels === []
            ? __('rubrics.blind.no_marked_fields')
            : __('rubrics.blind.marked_fields', ['fields' => implode(', ', $labels)]));
    }

    /** Guardar una rúbrica publicada con evaluaciones registradas crea una versión nueva (CA-011-3). */
    private static function willCreateNewVersion(mixed $livewire): bool
    {
        if (! $livewire instanceof EditRubric) {
            return false;
        }

        $rubric = $livewire->getRecord();

        return $rubric instanceof Rubric
            && $rubric->status === RubricStatus::Published
            && app(RubricUsageChecker::class)->hasRecordedReviews($rubric);
    }

    /** @param  array<string, mixed>  $state */
    private static function itemLabel(array $state): string
    {
        $label = $state['label'] ?? null;
        $text = is_array($label) ? (string) ($label[array_key_first($label)] ?? '') : '';

        return $text !== '' ? $text : __('rubrics.fields.untitled');
    }
}
