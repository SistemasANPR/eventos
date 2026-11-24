@php
    /**
     * Tablero del evaluador (plan 011, CA-011-50 y 52). Solo texto propio del sistema y nombres de evento
     * (escapados con `{{ }}`); ningún dato de autores.
     *
     * @var array{assigned: int, pending: int, in_progress: int, submitted: int, declined_conflict: int} $counts
     * @var list<array{id: string, name: string, open: bool}> $periods
     * @var list<array{id: string, name: string, open: bool}> $closedPeriods
     * @var string $listUrl
     */
    $cards = [
        'assigned' => ['count' => $counts['assigned'], 'color' => 'primary'],
        'pending' => ['count' => $counts['pending'], 'color' => 'gray'],
        'in_progress' => ['count' => $counts['in_progress'], 'color' => 'info'],
        'submitted' => ['count' => $counts['submitted'], 'color' => 'success'],
        'declined_conflict' => ['count' => $counts['declined_conflict'], 'color' => 'warning'],
    ];
@endphp

<div class="fi-reviewer-dashboard" style="display: grid; gap: 1.5rem;">
    @foreach ($closedPeriods as $period)
        <div role="status" style="padding: 0.75rem 1rem; border-radius: 0.5rem; border: 1px solid rgb(0 0 0 / 0.15);">
            <strong>{{ $period['name'] }}</strong>: {{ __('reviewer.dashboard.period_closed_notice') }}
        </div>
    @endforeach

    @if ($counts['assigned'] === 0)
        <x-filament::section>
            <p style="margin: 0; font-weight: 600;">{{ __('reviewer.dashboard.empty_heading') }}</p>
            <p style="margin: 0.25rem 0 0;">{{ __('reviewer.dashboard.empty_description') }}</p>
        </x-filament::section>
    @endif

    <section aria-label="{{ __('reviewer.dashboard.summary') }}">
        <dl style="display: grid; grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr)); gap: 1rem; margin: 0;">
            @foreach ($cards as $key => $card)
                <div class="fi-section" style="padding: 1rem;" data-card="{{ $key }}">
                    <dt class="fi-fo-field-label-content">{{ __("reviewer.dashboard.cards.{$key}") }}</dt>
                    <dd style="margin: 0.25rem 0 0; font-size: 1.75rem; font-weight: 700;">{{ $card['count'] }}</dd>
                </div>
            @endforeach
        </dl>
    </section>

    <x-filament::section :heading="__('reviewer.dashboard.periods')">
        @forelse ($periods as $period)
            <div style="display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; margin-bottom: 0.5rem; overflow-wrap: anywhere;">
                <span>{{ $period['name'] }}</span>
                <x-filament::badge :color="$period['open'] ? 'success' : 'danger'">
                    {{ $period['open'] ? __('reviewer.dashboard.period_open') : __('reviewer.dashboard.period_closed') }}
                </x-filament::badge>
            </div>
        @empty
            <p style="margin: 0;">{{ __('reviewer.dashboard.no_periods') }}</p>
        @endforelse
    </x-filament::section>

    @if ($counts['assigned'] > 0)
        <div>
            <x-filament::button tag="a" :href="$listUrl">{{ __('reviewer.dashboard.go_to_list') }}</x-filament::button>
        </div>
    @endif
</div>
