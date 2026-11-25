@php
    /**
     * Resultados de la evaluación (plan 011, F6, CA-011-22 a 24). Todo lo del usuario (títulos, nombres de
     * evaluadores y comentarios) se imprime escapado con `{{ }}`.
     *
     * @var \Illuminate\Pagination\LengthAwarePaginator<int, \App\Support\Reviews\SubmissionResult> $results
     * @var bool $onlyDiscrepancies
     * @var list<int> $pageSizes
     * @var array<string, string> $tracks
     * @var array<string, string> $calls
     */
    $locale = app()->getLocale();
    $score = static fn (?float $value, int $precision = 2): string => $value === null ? '—' : \Illuminate\Support\Number::format($value, precision: $precision, locale: $locale);
@endphp

<div class="fi-review-results" style="display: grid; gap: 1.25rem;">
    <p style="margin: 0;">{{ __('review_results.results.description') }}</p>

    <div style="display: flex; flex-wrap: wrap; gap: 1rem; align-items: end;">
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

        <label style="display: flex; gap: 0.5rem; align-items: center; min-height: 2.25rem;">
            <input type="checkbox" wire:model.live="onlyDiscrepancies">
            <span>{{ __('review_results.results.filters.discrepancies') }}</span>
        </label>
    </div>

    @if ($results->total() === 0)
        <x-filament::section>
            <p style="margin: 0; font-weight: 600;">{{ $onlyDiscrepancies ? __('review_results.results.empty_discrepancies_heading') : __('review_results.results.empty_heading') }}</p>
            <p style="margin: 0.25rem 0 0;">{{ $onlyDiscrepancies ? __('review_results.results.empty_discrepancies_description') : __('review_results.results.empty_description') }}</p>
        </x-filament::section>
    @else
        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; min-width: 44rem;">
                <thead>
                    <tr style="text-align: left;">
                        <th style="padding: 0.5rem;">{{ __('review_results.results.columns.rank') }}</th>
                        <th style="padding: 0.5rem;">{{ __('review_results.results.columns.title') }}</th>
                        <th style="padding: 0.5rem;">{{ __('review_results.results.columns.status') }}</th>
                        <th style="padding: 0.5rem;">{{ __('review_results.results.columns.evaluations') }}</th>
                        <th style="padding: 0.5rem;">{{ __('review_results.results.columns.average') }}</th>
                        <th style="padding: 0.5rem;">{{ __('review_results.results.columns.percentage') }}</th>
                        <th style="padding: 0.5rem;">{{ __('review_results.results.columns.deviation') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($results as $result)
                        <tr data-submission="{{ $result->submissionId }}" style="border-top: 1px solid rgb(0 0 0 / 0.1); vertical-align: top;">
                            <td style="padding: 0.5rem;" data-rank>{{ $result->rank ?? __('review_results.results.no_rank') }}</td>
                            <td style="padding: 0.5rem; overflow-wrap: anywhere;">
                                <details>
                                    <summary style="cursor: pointer;">{{ $result->title }}</summary>

                                    <div style="display: grid; gap: 0.75rem; margin-top: 0.75rem;">
                                        @if ($result->discrepancies !== [])
                                            <div data-discrepancies>
                                                <strong>{{ __('review_results.results.discrepancies_heading') }}</strong>
                                                <ul style="margin: 0.25rem 0 0; padding-left: 1.25rem;">
                                                    @foreach ($result->discrepancies as $discrepancy)
                                                        <li>
                                                            {{ __('review_results.results.discrepancy_item', ['label' => $discrepancy->label, 'spread' => $discrepancy->spread, 'threshold' => $discrepancy->threshold]) }}
                                                            <ul style="margin: 0; padding-left: 1.25rem;">
                                                                @foreach ($discrepancy->scores as $discrepancyScore)
                                                                    <li>{{ __('review_results.results.discrepancy_score', ['name' => $discrepancyScore['reviewer_name'], 'value' => $discrepancyScore['value']]) }}</li>
                                                                @endforeach
                                                            </ul>
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            </div>
                                        @endif

                                        <div>
                                            <strong>{{ __('review_results.results.by_reviewer') }}</strong>
                                            @forelse ($result->reviews as $review)
                                                <div style="margin-top: 0.5rem;" data-review="{{ $review->reviewerId }}">
                                                    <div>{{ $review->reviewerName }} · {{ __('review_results.results.reviewer_average', ['average' => $score($review->average), 'version' => $review->rubricVersion]) }}</div>
                                                    <ul style="margin: 0; padding-left: 1.25rem;">
                                                        @foreach ($review->scores as $answer)
                                                            <li>{{ $answer['label'] }}: {{ $answer['value'] }}</li>
                                                        @endforeach
                                                    </ul>
                                                    @foreach ($review->comments as $comment)
                                                        <p style="margin: 0.25rem 0 0; white-space: pre-line; overflow-wrap: anywhere;"><em>{{ $comment['label'] }}:</em> {{ $comment['text'] }}</p>
                                                    @endforeach
                                                </div>
                                            @empty
                                                <p style="margin: 0.25rem 0 0;">{{ __('review_results.results.no_score') }}</p>
                                            @endforelse
                                        </div>
                                    </div>
                                </details>
                            </td>
                            <td style="padding: 0.5rem;">
                                <x-filament::badge :color="$result->status->getColor()">{{ $result->status->getLabel() }}</x-filament::badge>
                            </td>
                            <td style="padding: 0.5rem;">
                                {{ __('review_results.results.evaluations_count', ['count' => $result->evaluations, 'min' => $result->minimum]) }}
                                @if ($result->incomplete)
                                    <x-filament::badge color="warning" :title="__('review_results.results.incomplete_hint', ['min' => $result->minimum])">{{ __('review_results.results.incomplete') }}</x-filament::badge>
                                @endif
                            </td>
                            <td style="padding: 0.5rem;" data-average>{{ $score($result->average) }}</td>
                            <td style="padding: 0.5rem;">{{ $result->percentage === null ? '—' : $score($result->percentage, 1).' %' }}</td>
                            <td style="padding: 0.5rem;" data-deviation>{{ $score($result->deviation) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <x-filament::pagination :paginator="$results" :page-options="$pageSizes" current-page-option-property="perPage" />
    @endif
</div>
