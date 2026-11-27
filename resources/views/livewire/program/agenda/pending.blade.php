{{--
    Panel de actividades sin programar (plan 031, DA-8). En esta fase solo se lee: arrastrarlas a la cuadrícula es de la F5.
--}}
<aside class="agenda-pending" aria-labelledby="agenda-pending-title-{{ $this->getId() }}">
    <h3 id="agenda-pending-title-{{ $this->getId() }}" class="agenda-pending__title">{{ __('program.agenda.pending_title') }}</h3>
    <p class="agenda-pending__help">{{ __('program.agenda.pending_help') }}</p>

    @if ($pending === [])
        <p class="agenda-pending__empty">{{ __('program.agenda.pending_empty') }}</p>
    @else
        <ul class="agenda-pending__list">
            @foreach ($pending as $item)
                <li class="agenda-pending__item" data-agenda-pending="{{ $item->activityId }}">
                    <span class="agenda-pending__name">{{ $item->title }}</span>
                    @unless ($item->isPublished)
                        <span class="agenda-badge">{{ __('program.agenda.unpublished') }}</span>
                    @endunless
                    @if ($item->speakers !== [])
                        <span class="agenda-pending__speakers">{{ implode(', ', $item->speakers) }}</span>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</aside>
