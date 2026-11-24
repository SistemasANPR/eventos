@php
    /**
     * Detalle del avance de un evaluador (plan 011, F6, CA-011-58): propuestas, estado de cada asignación y
     * última actividad. Sin calificaciones ni comentarios. Todo se imprime escapado con `{{ }}`.
     *
     * @var \App\Support\Reviews\ReviewerProgressDetailReport $report
     * @var list<int> $pageSizes
     * @var string $timezone
     */
    $locale = app()->getLocale();
@endphp

<div class="fi-review-progress-detail" style="display: grid; gap: 1.25rem;">
    <div>
        <div style="font-weight: 600;">{{ $report->name }}</div>
        <div style="overflow-wrap: anywhere;">{{ $report->email }}</div>
        <p data-progress style="margin: 0.5rem 0 0; font-size: 1.25rem; font-weight: 600;">{{ $report->progress->label() }}</p>
        <p style="margin: 0.25rem 0 0;">{{ __('review_results.detail.no_scores_note') }}</p>
    </div>

    @if ($report->assignments->total() === 0)
        <x-filament::section>
            <p style="margin: 0; font-weight: 600;">{{ __('review_results.detail.empty_heading') }}</p>
            <p style="margin: 0.25rem 0 0;">{{ __('review_results.detail.empty_description') }}</p>
        </x-filament::section>
    @else
        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; min-width: 32rem;">
                <thead>
                    <tr style="text-align: left;">
                        <th style="padding: 0.5rem;">{{ __('review_results.detail.columns.submission') }}</th>
                        <th style="padding: 0.5rem;">{{ __('review_results.detail.columns.status') }}</th>
                        <th style="padding: 0.5rem;">{{ __('review_results.detail.columns.last_activity') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($report->assignments as $assignment)
                        <tr data-submission="{{ $assignment->submissionId }}" style="border-top: 1px solid rgb(0 0 0 / 0.1);">
                            <td style="padding: 0.5rem; overflow-wrap: anywhere;">{{ $assignment->title }}</td>
                            <td style="padding: 0.5rem;">
                                <x-filament::badge :color="$assignment->status->getColor()">{{ $assignment->status->getLabel() }}</x-filament::badge>
                            </td>
                            <td style="padding: 0.5rem;">
                                {{ $assignment->lastActivityAt === null ? __('review_results.detail.no_activity') : $assignment->lastActivityAt->copy()->timezone($timezone)->locale($locale)->isoFormat('LLL') }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <x-filament::pagination :paginator="$report->assignments" :page-options="$pageSizes" current-page-option-property="perPage" />
    @endif
</div>
