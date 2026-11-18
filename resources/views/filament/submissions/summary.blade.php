@php
    /**
     * Resumen de propuestas por eje, tipo y día (plan 009, H-009-4). Todo se imprime escapado.
     *
     * @var array{total: int, byTrack: list<array{label: string, count: int}>, byType: list<array{label: string, count: int}>, byDay: list<array{day: string, label: string, count: int}>} $summary
     */
@endphp

@if ($summary['total'] === 0)
    <p style="margin: 0;">{{ __('submission_panel.summary.empty') }}</p>
@else
    <div style="display: grid; gap: 1.25rem;">
        <p style="margin: 0;"><strong>{{ __('submission_panel.summary.total', ['count' => $summary['total']]) }}</strong></p>

        @foreach ([
            'by_track' => $summary['byTrack'],
            'by_type' => $summary['byType'],
        ] as $key => $rows)
            <div>
                <h3 style="margin: 0 0 0.5rem; font-weight: 600;">{{ __('submission_panel.summary.'.$key) }}</h3>
                <table style="width: 100%; border-collapse: collapse;">
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                <td style="padding: 0.25rem 0; overflow-wrap: anywhere;">{{ $row['label'] }}</td>
                                <td style="padding: 0.25rem 0; text-align: right;">{{ $row['count'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endforeach

        <div>
            <h3 style="margin: 0 0 0.5rem; font-weight: 600;">{{ __('submission_panel.summary.by_day') }}</h3>
            <table style="width: 100%; border-collapse: collapse;">
                <tbody>
                    @foreach ($summary['byDay'] as $row)
                        <tr>
                            {{-- Se muestra la fecha ISO (la que se filtra y exporta) y su forma larga. --}}
                            <td style="padding: 0.25rem 0;">{{ $row['day'] }} <span>({{ $row['label'] }})</span></td>
                            <td style="padding: 0.25rem 0; text-align: right;">{{ $row['count'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
