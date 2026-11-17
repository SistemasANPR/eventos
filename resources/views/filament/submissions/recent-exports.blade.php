@php
    /**
     * Exportaciones recientes de quien mira (plan 009, CA-009-19). Todo se imprime escapado. Los enlaces
     * son URL firmadas de 5 minutos que solo sirven a esta persona.
     *
     * @var list<array{date: string, rows: int, format: string, url: string}> $exports
     */
@endphp

<div style="display: grid; gap: 0.75rem;">
    <p style="margin: 0;">{{ __('submission_panel.export.recent_note') }}</p>

    @forelse ($exports as $export)
        <div style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.5rem;">
            <span>{{ __('submission_panel.export.recent_row', ['date' => $export['date'], 'rows' => $export['rows'], 'format' => $export['format']]) }}</span>
            <x-filament::button tag="a" :href="$export['url']" size="sm" icon="heroicon-o-arrow-down-tray">
                {{ __('submission_panel.export.download') }}
            </x-filament::button>
        </div>
    @empty
        <p style="margin: 0;">{{ __('submission_panel.export.recent_empty') }}</p>
    @endforelse
</div>
