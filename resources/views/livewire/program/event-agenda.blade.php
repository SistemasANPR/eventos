{{--
    Agenda de lectura (plan 031, F4). Solo lectura: la cuadrícula día × lugar, el selector de día, el panel de pendientes y
    los estados (error, sin lugares, sin actividades, cargando). Los estilos están en resources/css/filament/admin/agenda.css.
    $failed: la lectura falló; $agenda: AgendaDay (nulo si falló); $event: el evento (zona horaria y formato de hora).
--}}
<section class="agenda" aria-labelledby="agenda-title-{{ $this->getId() }}">
    <h2 id="agenda-title-{{ $this->getId() }}" class="agenda-title">{{ __('program.agenda.title') }}</h2>

    @if ($failed)
        <div class="agenda-state agenda-state--error" role="alert">
            <p class="agenda-state__title">{{ __('program.agenda.error_title') }}</p>
            <p class="agenda-state__body">{{ __('program.agenda.error_body') }}</p>
        </div>
    @else
        @php
            $columns = $agenda->columns;
            $hasCells = collect($columns)->contains(fn ($column): bool => $column->cells !== []);
            $hasPlaces = collect($columns)->contains(fn ($column): bool => $column->kind !== \App\Support\Program\Agenda\AgendaColumnKind::None);
            $hasPending = $agenda->pending !== [];
            $rows = max(1, intdiv($agenda->endMinute - $agenda->startMinute, max(1, $agenda->stepMinutes)));
        @endphp

        <div class="agenda-toolbar">
            @if ($agenda->days !== [])
                <nav class="agenda-days" aria-label="{{ __('program.agenda.days_label') }}">
                    @foreach ($agenda->days as $day)
                        <button type="button"
                                wire:click="selectDay('{{ $day }}')"
                                class="agenda-day-button"
                                @if ($day === $agenda->date) aria-current="date" @endif
                                aria-label="{{ $this->dayName($day) }}">{{ $this->dayLabel($day) }}</button>
                    @endforeach
                </nav>
            @endif

            <p class="agenda-timezone">{{ __('program.agenda.timezone', ['timezone' => $event->timezone]) }}</p>
        </div>

        <div wire:loading wire:target="selectDay" class="agenda-loading" role="status">{{ __('program.agenda.loading') }}</div>

        @unless ($hasPlaces)
            <div class="agenda-state agenda-state--notice">
                <p class="agenda-state__title">{{ __('program.agenda.no_places_title') }}</p>
                <p class="agenda-state__body">{{ __('program.agenda.no_places_body') }}</p>
                <a class="agenda-link" href="{{ \App\Filament\Resources\Events\EventResource::getUrl('rooms', ['record' => $event]) }}">{{ __('program.agenda.no_places_link') }}</a>
            </div>
        @endunless

        <div class="agenda-layout" wire:loading.class="agenda-busy" wire:target="selectDay">
            <div class="agenda-main">
                @if (! $hasCells && ! $hasPending)
                    <div class="agenda-state agenda-state--empty">
                        <p class="agenda-state__title">{{ __('program.agenda.empty_general_title') }}</p>
                        <p class="agenda-state__body">{{ __('program.agenda.empty_general_body') }}</p>
                    </div>
                @elseif (! $hasCells)
                    <div class="agenda-state agenda-state--empty">
                        <p class="agenda-state__body">{{ __('program.agenda.empty_day') }}</p>
                    </div>
                @else
                    <div class="agenda-scroll" tabindex="0" role="region" aria-label="{{ __('program.agenda.grid_label') }}">
                        <div class="agenda-grid" style="--agenda-cols: {{ count($columns) }}; --agenda-rows: {{ $rows }};">
                            <div class="agenda-corner" aria-hidden="true">{{ __('program.agenda.hours') }}</div>

                            @foreach ($columns as $column)
                                <div class="agenda-head" data-agenda-column="{{ $column->key }}">
                                    <span class="agenda-head__name">{{ $this->columnLabel($column) }}</span>
                                    @if ($column->capacity !== null)
                                        <span class="agenda-head__meta">{{ __('program.agenda.capacity', ['count' => $column->capacity]) }}</span>
                                    @endif
                                </div>
                            @endforeach

                            <div class="agenda-axis" aria-hidden="true">
                                @for ($minute = (int) (ceil($agenda->startMinute / 60) * 60); $minute < $agenda->endMinute; $minute += 60)
                                    <span class="agenda-axis__hour" style="--agenda-start: {{ $this->rows($agenda, $minute - $agenda->startMinute) }};">{{ $this->timeLabel($minute) }}</span>
                                @endfor
                            </div>

                            @foreach ($columns as $column)
                                <div class="agenda-body" data-agenda-body="{{ $column->key }}">
                                    @foreach ($column->cells as $cell)
                                        @include('livewire.program.agenda.cell', ['cell' => $cell, 'agenda' => $agenda])
                                    @endforeach
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>

            @include('livewire.program.agenda.pending', ['pending' => $agenda->pending])
        </div>
    @endif
</section>
