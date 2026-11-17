<?php

declare(strict_types=1);

namespace App\Filament\Resources\Submissions\Tables;

use App\Enums\SubmissionStatus;
use App\Filament\Resources\Submissions\SubmissionResource;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\Submission;
use App\Models\SubmissionType;
use App\Models\Track;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Tabla de propuestas del evento de la ruta (plan 009, H-009-1): paginada en el
 * servidor con tope de 100 por página (CA-009-3) y filtros por convocatoria, eje,
 * tipo, estado y rango de fechas (CA-009-2). Las opciones de los filtros salen solo
 * del evento actual; un valor que no está entre ellas (de otro evento, de otra
 * organización o inexistente) produce el mismo error de validación genérico, sin
 * revelar si existe (CA-009-4).
 */
final class SubmissionsTable
{
    /** Tope de filas por página; AGENTS.md: listas paginadas, máximo 100. */
    public const MAX_PER_PAGE = 100;

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label(__('submission_panel.columns.title'))
                    ->searchable()
                    ->wrap(),
                TextColumn::make('call')
                    ->label(__('submission_panel.columns.call'))
                    ->state(fn (Submission $record): string => (string) $record->call?->getTranslation('title', app()->getLocale(), true)),
                TextColumn::make('submissionType')
                    ->label(__('submission_panel.columns.type'))
                    ->state(fn (Submission $record): string => $record->submissionType->label(app()->getLocale())),
                TextColumn::make('track')
                    ->label(__('submission_panel.columns.track'))
                    ->state(fn (Submission $record): string => (string) $record->track->getTranslation('name', app()->getLocale(), true)),
                TextColumn::make('status')
                    ->label(__('submission_panel.columns.status'))
                    ->badge(),
                TextColumn::make('submitted_at')
                    ->label(__('submission_panel.columns.submitted_at'))
                    ->state(fn (Submission $record): string => $record->submitted_at
                        ->setTimezone($record->event->timezone)
                        ->isoFormat('L LT'))
                    ->sortable(),
            ])
            ->recordUrl(fn (Submission $record): string => SubmissionResource::getUrl('view', ['event' => $record->event, 'record' => $record]))
            ->defaultSort('submitted_at', 'desc')
            ->filters([
                self::selectFilter('call_for_submissions_id', 'call', self::callOptions(...)),
                self::selectFilter('track_id', 'track', self::trackOptions(...)),
                self::selectFilter('submission_type_id', 'type', self::typeOptions(...)),
                self::statusFilter(),
                self::dateRangeFilter(),
            ])
            ->paginated([10, 25, 50, self::MAX_PER_PAGE])
            ->defaultPaginationPageOption(25)
            // Sin esto Filament acepta cualquier tamaño que llegue del cliente (CA-009-3).
            ->enforcePaginationPageOptions()
            ->emptyStateHeading(__('submission_panel.empty'));
    }

    /** @param  callable(Event): array<string, string>  $options */
    private static function selectFilter(string $column, string $name, callable $options): SelectFilter
    {
        return SelectFilter::make($column)
            ->label(__("submission_panel.columns.{$name}"))
            ->options(fn ($livewire): array => $options(self::event($livewire)))
            ->query(function (Builder $query, array $data, $livewire) use ($column, $options): Builder {
                $value = $data['value'] ?? null;

                if (blank($value)) {
                    return $query;
                }

                if (! array_key_exists((string) $value, $options(self::event($livewire)))) {
                    throw self::invalidFilter();
                }

                return $query->where($column, $value);
            });
    }

    private static function statusFilter(): SelectFilter
    {
        return SelectFilter::make('status')
            ->label(__('submission_panel.columns.status'))
            ->options(SubmissionStatus::class)
            ->query(function (Builder $query, array $data): Builder {
                $value = $data['value'] ?? null;

                if (blank($value)) {
                    return $query;
                }

                if (SubmissionStatus::tryFrom((string) $value) === null) {
                    throw self::invalidFilter();
                }

                return $query->where('status', $value);
            });
    }

    private static function dateRangeFilter(): Filter
    {
        return Filter::make('submitted_range')
            ->label(__('submission_panel.filters.range'))
            ->schema([
                DatePicker::make('from')->label(__('submission_panel.filters.from')),
                DatePicker::make('until')->label(__('submission_panel.filters.until')),
            ])
            ->columns(2)
            ->query(function (Builder $query, array $data, $livewire): Builder {
                $timezone = self::event($livewire)->timezone;
                $from = self::day($data['from'] ?? null, $timezone);
                $until = self::day($data['until'] ?? null, $timezone);

                if ($from !== null && $until !== null && $from->greaterThan($until)) {
                    throw self::invalidFilter();
                }

                return $query
                    ->when($from, fn (Builder $q, CarbonImmutable $day): Builder => $q->where('submitted_at', '>=', $day->startOfDay()->utc()))
                    ->when($until, fn (Builder $q, CarbonImmutable $day): Builder => $q->where('submitted_at', '<=', $day->endOfDay()->utc()));
            })
            ->indicateUsing(fn (array $data): array => array_values(array_filter([
                filled($data['from'] ?? null) ? __('submission_panel.filters.from').': '.$data['from'] : null,
                filled($data['until'] ?? null) ? __('submission_panel.filters.until').': '.$data['until'] : null,
            ])));
    }

    /** El día elegido, a las 00:00 de la zona del evento; un valor mal formado es un error de validación. */
    private static function day(mixed $value, string $timezone): ?CarbonImmutable
    {
        if (blank($value)) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', (string) $value, $timezone);
        } catch (InvalidFormatException) {
            throw self::invalidFilter();
        }
    }

    /** @return array<string, string> */
    private static function callOptions(Event $event): array
    {
        return CallForSubmissions::query()->where('event_id', $event->id)->get()
            ->mapWithKeys(fn (CallForSubmissions $call): array => [$call->id => (string) $call->getTranslation('title', app()->getLocale(), true)])
            ->all();
    }

    /** @return array<string, string> */
    private static function trackOptions(Event $event): array
    {
        return Track::query()->where('event_id', $event->id)->orderBy('position')->get()
            ->mapWithKeys(fn (Track $track): array => [$track->id => (string) $track->getTranslation('name', app()->getLocale(), true)])
            ->all();
    }

    /** @return array<string, string> */
    private static function typeOptions(Event $event): array
    {
        return SubmissionType::query()->where('event_id', $event->id)->orderBy('position')->get()
            ->mapWithKeys(fn (SubmissionType $type): array => [$type->id => $type->label(app()->getLocale())])
            ->all();
    }

    private static function event(mixed $livewire): Event
    {
        $event = $livewire->getParentRecord();

        return $event instanceof Event ? $event : throw new LogicException('La tabla de propuestas necesita un evento en la ruta.');
    }

    /**
     * Filtros de selección cuyo valor no pertenece al evento (ajeno, inexistente o mal formado). La página
     * los valida al cambiar el filtro y los limpia, para que el error se vea como validación y no como un 500.
     *
     * @param  array<string, mixed>  $filters  estado `tableFilters` de la página
     * @return list<string>
     */
    public static function invalidSelectFilters(array $filters, Event $event): array
    {
        $options = [
            'call_for_submissions_id' => self::callOptions($event),
            'track_id' => self::trackOptions($event),
            'submission_type_id' => self::typeOptions($event),
        ];

        $invalid = [];

        foreach ($options as $name => $allowed) {
            $value = $filters[$name]['value'] ?? null;

            if (filled($value) && ! array_key_exists((string) $value, $allowed)) {
                $invalid[] = $name;
            }
        }

        return $invalid;
    }

    /** Mismo error para un valor ajeno, inexistente o mal formado: no revela si existe. */
    public static function invalidFilter(): ValidationException
    {
        return ValidationException::withMessages(['tableFilters' => __('submission_panel.filters.invalid')]);
    }
}
