<?php

declare(strict_types=1);

namespace App\Filament\Resources\Events\Tables;

use App\Enums\EventStatus;
use App\Filament\Resources\Events\Actions\LandingActions;
use App\Filament\Resources\Events\Actions\LifecycleActions;
use App\Filament\Resources\Events\EventResource;
use App\Models\Event;
use App\Support\Events\EventTime;
use Carbon\CarbonImmutable;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;

/**
 * Lista de eventos de la organización, como tarjetas (plan 015). Es también el SELECTOR de evento
 * (plan 003, DA-8): sin evento elegido, las pantallas por evento piden elegir
 * uno aquí; nunca toman uno al azar.
 */
final class EventsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                // Orden de lectura: qué evento es, cuándo y dónde, y al pie su estado (plan 015; ver el plan 024 para la evolución a tarjeta operativa).
                Stack::make([
                    TextColumn::make('name')
                        ->label(__('events.table.name'))
                        ->state(fn (Event $record): string => self::displayName($record))
                        ->weight(FontWeight::SemiBold)
                        ->size(TextSize::Large)
                        // Deja sitio al menú de opciones, que va arriba a la derecha de la tarjeta.
                        ->extraAttributes(['class' => 'eventos-card-title']),
                    TextColumn::make('starts_at')
                        ->label(__('events.table.starts_at'))
                        ->state(fn (Event $record): string => self::displaySchedule($record))
                        ->icon(Heroicon::OutlinedCalendarDays)
                        ->color('gray')
                        ->sortable(),
                    TextColumn::make('city')
                        ->label(__('events.fields.city'))
                        ->icon(Heroicon::OutlinedMapPin)
                        ->color('gray'),
                    TextColumn::make('status')
                        ->label(__('events.fields.status'))
                        ->badge()
                        // El estado no se comunica solo con color (design.md §3.1): lleva icono y texto.
                        ->icon(fn (EventStatus $state): Heroicon => match ($state) {
                            EventStatus::Draft => Heroicon::OutlinedPencilSquare,
                            EventStatus::Published => Heroicon::OutlinedCheckCircle,
                            EventStatus::Archived => Heroicon::OutlinedArchiveBox,
                        })
                        ->grow(false),
                ])->space(2),
            ])
            // Tarjetas: una columna en móvil, dos en tablet y tres en escritorio (plan 015, DA-4).
            ->contentGrid(['default' => 1, 'md' => 2, 'xl' => 3])
            // El menú de opciones va arriba a la derecha de la tarjeta (el CSS del tema lo coloca sobre ella).
            ->recordActionsPosition(RecordActionsPosition::BeforeColumns)
            ->recordActionsAlignment(Alignment::End->value)
            ->defaultSort('starts_at', 'desc')
            ->recordUrl(fn (Event $record): string => EventResource::getUrl('view', ['record' => $record]))
            // Sin «Ver»: la tarjeta entera abre el evento.
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    LandingActions::design(),
                    LifecycleActions::publish(),
                    LifecycleActions::archive(),
                ])->label(__('events.table.actions')),
            ])
            ->emptyStateHeading(__('events.table.empty_heading'))
            ->emptyStateDescription(__('events.table.empty_description'))
            ->emptyStateActions([CreateAction::make()]);
    }

    /** Nombre en el idioma por defecto del evento, con respaldo a cualquier otro. */
    public static function displayName(Event $event): string
    {
        return (string) $event->getTranslation('name', $event->default_locale, true);
    }

    /** Rango de días en la zona del evento, corto: «10–12 mar. 2027», «28 feb. – 2 mar. 2027» o «10 mar. 2027». Sin hora. */
    public static function displayDateRange(Event $event): string
    {
        $start = CarbonImmutable::instance($event->starts_at)->setTimezone($event->timezone)->locale(app()->getLocale());
        $end = CarbonImmutable::instance($event->ends_at)->setTimezone($event->timezone)->locale(app()->getLocale());

        return match (true) {
            $start->isSameDay($end) => $start->translatedFormat('j M Y'),
            $start->isSameMonth($end) => $start->translatedFormat('j').'–'.$end->translatedFormat('j M Y'),
            $start->year === $end->year => $start->translatedFormat('j M').' – '.$end->translatedFormat('j M Y'),
            default => $start->translatedFormat('j M Y').' – '.$end->translatedFormat('j M Y'),
        };
    }

    /** Cuánto falta, en días de la zona del evento: «En 112 días», «Mañana», «En curso» o «Finalizó». Un archivado no la lleva. */
    public static function displayProximity(Event $event): ?string
    {
        if ($event->status === EventStatus::Archived) {
            return null;
        }

        $today = CarbonImmutable::now($event->timezone)->startOfDay();
        $first = CarbonImmutable::instance($event->starts_at)->setTimezone($event->timezone)->startOfDay();
        $last = CarbonImmutable::instance($event->ends_at)->setTimezone($event->timezone)->startOfDay();

        if ($today->greaterThan($last)) {
            return __('events.card.ended');
        }

        if ($today->greaterThanOrEqualTo($first)) {
            return __('events.card.in_progress');
        }

        $days = (int) $today->diffInDays($first);

        return $days === 1 ? __('events.card.tomorrow') : trans_choice('events.card.in_days', $days, ['count' => $days]);
    }

    /** Línea de fechas de la tarjeta: el rango y, si aplica, cuánto falta. */
    public static function displaySchedule(Event $event): string
    {
        $proximity = self::displayProximity($event);

        return self::displayDateRange($event).($proximity === null ? '' : ' · '.$proximity);
    }

    /** Fechas en la zona horaria DEL EVENTO (se guardan en UTC) y con su formato de hora, p. ej. «10 mar 2027, 9:00 a. m. – 12 mar 2027, 5:00 p. m.». */
    public static function displayDates(Event $event): string
    {
        return EventTime::for($event)->range($event->starts_at, $event->ends_at, 'j M Y');
    }
}
