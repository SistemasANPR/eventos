<?php

declare(strict_types=1);

namespace App\Filament\Resources\Events\Pages;

use App\Enums\ReviewAssignmentScope;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Submissions\Actions\ReviewAssignmentActions;
use App\Models\Event;
use App\Models\ReviewAssignment;
use App\Models\ReviewScopeRule;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use LogicException;

/**
 * Reglas permanentes de alcance del evento (plan 011, F3b, DA-6): qué evaluador recibe solo las propuestas que
 * lleguen a un eje o a una convocatoria. Se crean al asignar por eje o convocatoria; aquí se ven y se quitan
 * (las asignaciones ya hechas se conservan, CA-011-43) y se aplican con «Asignar propuestas nuevas». El evento
 * sale de la ruta (404 si es de otra organización); entra quien tiene `review_assignments.manage`.
 */
class ManageEventAssignmentRules extends ManageRelatedRecords
{
    protected static string $resource = EventResource::class;

    protected static string $relationship = 'reviewScopeRules';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    public static function getNavigationLabel(): string
    {
        return __('review_assignments.rules.navigation');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public static function canAccess(array $parameters = []): bool
    {
        return Gate::allows('viewAny', ReviewAssignment::class);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['reviewer', 'track', 'call']))
            ->columns([
                TextColumn::make('reviewer.name')->label(__('review_assignments.rules.columns.reviewer'))->weight('medium'),
                TextColumn::make('scope_type')
                    ->label(__('review_assignments.rules.columns.type'))
                    ->state(fn (Model $record): string => $this->rule($record)->scope_type === ReviewAssignmentScope::Track
                        ? __('review_assignments.form.scope_track')
                        : __('review_assignments.form.scope_call'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('scope')
                    ->label(__('review_assignments.rules.columns.scope'))
                    ->state(fn (Model $record): string => $this->scopeName($this->rule($record))),
                TextColumn::make('created_at')
                    ->label(__('review_assignments.rules.columns.created_at'))
                    ->dateTime()
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginationPageOptions([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->recordActions([ReviewAssignmentActions::removeRule()])
            ->emptyStateHeading(__('review_assignments.rules.empty_heading'))
            ->emptyStateDescription(__('review_assignments.rules.empty_description'));
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label(__('review_assignments.rules.back'))
                ->icon(Heroicon::OutlinedArrowLeft)
                ->color('gray')
                ->url(fn (): string => EventResource::getUrl('reviewers', ['record' => $this->event()])),
            ReviewAssignmentActions::syncNew(),
        ];
    }

    private function scopeName(ReviewScopeRule $rule): string
    {
        $locale = app()->getLocale();

        return $rule->scope_type === ReviewAssignmentScope::Track
            ? (string) $rule->track?->label($locale)
            : (string) $rule->call?->getTranslation('title', $locale, true);
    }

    private function rule(Model $record): ReviewScopeRule
    {
        return $record instanceof ReviewScopeRule ? $record : throw new LogicException('La fila no es una regla de alcance.');
    }

    private function event(): Event
    {
        $owner = $this->getOwnerRecord();

        return $owner instanceof Event ? $owner : throw new LogicException('Las reglas necesitan un evento.');
    }
}
