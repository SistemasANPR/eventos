{{--
    Una actividad en la cuadrícula (plan 031, F4). Los minutos ya vienen recortados al día. La posición y el alto salen de
    variables CSS (--agenda-start y --agenda-span, en filas de la cuadrícula) y el carril de --agenda-lane y --agenda-lanes.
    Cada insignia lleva texto (no solo color): choque de ponente o de lugar con el nombre de la otra actividad, oculta por
    reversa, no publicada, varios días y «continúa al día siguiente».
--}}
@php
    $start = max($cell->startMinute, $agenda->startMinute);
    $end = min(max($cell->endMinute, $start), $agenda->endMinute);
    $range = $this->timeLabel($cell->startMinute).' – '.$this->timeLabel($cell->endMinute);
@endphp
<article class="agenda-cell{{ $cell->isPublished ? '' : ' agenda-cell--muted' }}{{ $cell->clashes !== [] ? ' agenda-cell--clash' : '' }}"
         data-agenda-cell="{{ $cell->activityId }}"
         tabindex="0"
         style="--agenda-start: {{ $this->rows($agenda, $start - $agenda->startMinute) }}; --agenda-span: {{ $this->rows($agenda, $end - $start) }}; --agenda-lane: {{ $cell->lane }}; --agenda-lanes: {{ max(1, $cell->lanes) }};"
         title="{{ $cell->title }} · {{ $range }}">
    <p class="agenda-cell__time">{{ $range }}</p>
    <h3 class="agenda-cell__title">{{ $cell->title }}</h3>

    @if ($cell->placeName !== null)
        <p class="agenda-cell__place">{{ $cell->placeName }}</p>
    @endif

    @if ($cell->speakers !== [])
        <p class="agenda-cell__speakers"><span class="agenda-sr">{{ __('program.agenda.speakers') }}: </span>{{ implode(', ', $cell->speakers) }}</p>
    @endif

    @if ($cell->quota !== null)
        <p class="agenda-cell__quota">{{ __('program.agenda.quota', ['count' => $cell->quota]) }}</p>
    @endif

    <ul class="agenda-badges">
        @foreach ($cell->clashes as $clash)
            <li class="agenda-badge agenda-badge--clash" data-agenda-clash="{{ $clash->type->value }}">
                @if ($clash->type === \App\Support\Program\Agenda\AgendaClashType::Speaker)
                    {{ __('program.agenda.clash_speaker', ['speaker' => (string) $clash->speaker, 'title' => $clash->withTitle]) }}
                @else
                    {{ __('program.agenda.clash_room', ['title' => $clash->withTitle]) }}
                @endif
            </li>
        @endforeach

        @if ($cell->isHiddenByReversal)
            <li class="agenda-badge agenda-badge--danger">{{ __('program.agenda.hidden_by_reversal') }}</li>
        @endif

        @if (! $cell->isPublished)
            <li class="agenda-badge">{{ __('program.agenda.unpublished') }}</li>
        @endif

        @if ($cell->isMultiDay)
            <li class="agenda-badge">{{ __('program.agenda.multi_day') }}</li>
        @endif

        @if ($cell->continuesNextDay)
            <li class="agenda-badge">{{ __('program.agenda.continues_next_day') }}</li>
        @endif
    </ul>
</article>
