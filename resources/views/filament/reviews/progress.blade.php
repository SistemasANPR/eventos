@php
    /**
     * Avance por evaluador (plan 011, F6, CA-011-25 y 55 a 59). Solo conteos y estados: nunca calificaciones
     * ni comentarios. Los nombres se imprimen escapados con `{{ }}`.
     *
     * @var \Illuminate\Pagination\LengthAwarePaginator<int, \App\Support\Reviews\ReviewerProgressRow> $rows
     * @var \App\Support\Reviews\ReviewerProgress $overall
     * @var int $threshold
     * @var list<int> $pageSizes
     * @var bool $periodOpen
     * @var string|null $closedAt
     * @var array<string, string> $allReviewers
     * @var array<string, string> $tracks
     * @var array<string, string> $calls
     * @var \Closure(string): string $detailUrl
     */
@endphp

<div class="fi-review-progress" style="display: grid; gap: 1.25rem;">
    <p style="margin: 0;">{{ __('review_results.progress.description') }}</p>

    <div style="display: flex; flex-wrap: wrap; gap: 1rem; align-items: center;">
        <div>
            <strong>{{ __('review_results.progress.period') }}:</strong>
            <x-filament::badge :color="$periodOpen ? 'success' : 'danger'" data-period>
                {{ $periodOpen ? __('review_results.progress.period_open') : __('review_results.progress.period_closed') }}
            </x-filament::badge>
            @if ($closedAt !== null)
                <span>{{ __('review_results.progress.period_closed_at', ['date' => $closedAt]) }}</span>
            @endif
        </div>
    </div>

    <x-filament::section :heading="__('review_results.progress.overall')">
        <p data-overall style="margin: 0; font-size: 1.25rem; font-weight: 600;">{{ $overall->label() }}</p>
    </x-filament::section>

    <div style="display: flex; flex-wrap: wrap; gap: 1rem; align-items: end;">
        <label style="display: grid; gap: 0.25rem; min-width: 12rem;">
            <span class="fi-fo-field-label-content">{{ __('review_results.progress.filters.reviewer') }}</span>
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="reviewer">
                    <option value="">{{ __('review_results.progress.filters.all_reviewers') }}</option>
                    @foreach ($allReviewers as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </label>

        <label style="display: grid; gap: 0.25rem; min-width: 12rem;">
            <span class="fi-fo-field-label-content">{{ __('review_results.results.filters.track') }}</span>
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="track">
                    <option value="">{{ __('review_results.results.filters.all_tracks') }}</option>
                    @foreach ($tracks as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </label>

        <label style="display: grid; gap: 0.25rem; min-width: 12rem;">
            <span class="fi-fo-field-label-content">{{ __('review_results.results.filters.call') }}</span>
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="call">
                    <option value="">{{ __('review_results.results.filters.all_calls') }}</option>
                    @foreach ($calls as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </label>

        <label style="display: grid; gap: 0.25rem; min-width: 12rem;">
            <span class="fi-fo-field-label-content">{{ __('review_results.progress.filters.sort') }}</span>
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="sort">
                    <option value="asc">{{ __('review_results.progress.filters.sort_asc') }}</option>
                    <option value="desc">{{ __('review_results.progress.filters.sort_desc') }}</option>
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </label>

        <label style="display: grid; gap: 0.25rem; width: 9rem;">
            <span class="fi-fo-field-label-content">{{ __('review_results.progress.filters.threshold') }}</span>
            <x-filament::input.wrapper>
                <x-filament::input type="number" min="0" max="100" wire:model.live.debounce.500ms="threshold" />
            </x-filament::input.wrapper>
        </label>
    </div>

    @if ($rows->total() === 0)
        <x-filament::section>
            <p style="margin: 0; font-weight: 600;">{{ __('review_results.progress.empty_heading') }}</p>
            <p style="margin: 0.25rem 0 0;">{{ __('review_results.progress.empty_description') }}</p>
        </x-filament::section>
    @else
        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; min-width: 40rem;">
                <thead>
                    <tr style="text-align: left;">
                        <th style="padding: 0.5rem;">{{ __('review_results.progress.columns.reviewer') }}</th>
                        <th style="padding: 0.5rem;">{{ __('review_results.progress.columns.progress') }}</th>
                        <th style="padding: 0.5rem;">{{ __('review_results.progress.columns.pending') }}</th>
                        <th style="padding: 0.5rem;">{{ __('review_results.progress.columns.in_progress') }}</th>
                        <th style="padding: 0.5rem;">{{ __('review_results.progress.columns.submitted') }}</th>
                        <th style="padding: 0.5rem;">{{ __('review_results.progress.columns.declined_conflict') }}</th>
                        <th style="padding: 0.5rem;"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        @php($low = $row->progress->isBelow($threshold))
                        <tr data-reviewer="{{ $row->reviewerId }}" @if ($low) data-low="true" style="border-top: 1px solid rgb(0 0 0 / 0.1); background: rgb(245 158 11 / 0.15);" @else style="border-top: 1px solid rgb(0 0 0 / 0.1);" @endif>
                            <td style="padding: 0.5rem; overflow-wrap: anywhere;">
                                <div style="font-weight: 600;">{{ $row->name }}</div>
                                <div>{{ $row->email }}</div>
                            </td>
                            <td style="padding: 0.5rem;" data-progress>
                                {{ $row->progress->label() }}
                                @if ($low)
                                    <x-filament::badge color="warning">{{ __('review_results.progress.below_threshold') }}</x-filament::badge>
                                @endif
                            </td>
                            <td style="padding: 0.5rem;">{{ $row->progress->pending }}</td>
                            <td style="padding: 0.5rem;">{{ $row->progress->inProgress }}</td>
                            <td style="padding: 0.5rem;">{{ $row->progress->submitted }}</td>
                            <td style="padding: 0.5rem;">{{ $row->progress->declinedConflict }}</td>
                            <td style="padding: 0.5rem;">
                                <x-filament::link :href="$detailUrl($row->reviewerId)">{{ __('review_results.progress.detail') }}</x-filament::link>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <x-filament::pagination :paginator="$rows" :page-options="$pageSizes" current-page-option-property="perPage" />
    @endif
</div>
