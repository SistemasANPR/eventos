@php
    /**
     * Detalle de una propuesta (plan 009, H-009-2). TODO el texto viene del usuario: se imprime solo con
     * `{{ }}` (escapado). Nunca `{!! !!}` ni `@js` de contenido, y las respuestas no se tratan como Markdown.
     *
     * @var \App\Models\Submission $submission
     * @var array{answers: list<array<string, mixed>>, participants: list<array<string, mixed>>, canDownload: bool} $detail
     * @var string $submittedAt
     * @var array{title: string, url: string}|null $activityLink
     */
@endphp

<div class="fi-submission-detail" style="display: grid; gap: 1.5rem;">
    <x-filament::section :heading="__('submission_panel.detail.general')">
        <dl style="display: grid; grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr)); gap: 1rem; margin: 0;">
            <div>
                <dt class="fi-fo-field-label-content">{{ __('submission_panel.columns.title') }}</dt>
                <dd style="margin: 0; overflow-wrap: anywhere;">{{ $submission->title }}</dd>
            </div>
            <div>
                <dt class="fi-fo-field-label-content">{{ __('submission_panel.columns.status') }}</dt>
                <dd style="margin: 0;">
                    <x-filament::badge :color="$submission->status->getColor()">{{ $submission->status->getLabel() }}</x-filament::badge>
                    @if ($submission->origin === \App\Enums\SubmissionOrigin::Invited)
                        <x-filament::badge color="info">{{ $submission->origin->getLabel() }}</x-filament::badge>
                    @endif
                </dd>
            </div>
            <div>
                <dt class="fi-fo-field-label-content">{{ __('submission_panel.columns.type') }}</dt>
                <dd style="margin: 0;">{{ $submission->submissionType->label(app()->getLocale()) }}</dd>
            </div>
            <div>
                <dt class="fi-fo-field-label-content">{{ __('submission_panel.columns.track') }}</dt>
                <dd style="margin: 0;">{{ $submission->track->getTranslation('name', app()->getLocale(), true) }}</dd>
            </div>
            @if ($submission->call !== null)
                <div>
                    <dt class="fi-fo-field-label-content">{{ __('submission_panel.columns.call') }}</dt>
                    <dd style="margin: 0;">{{ $submission->call->getTranslation('title', app()->getLocale(), true) }}</dd>
                </div>
            @endif
            @if ($activityLink !== null)
                <div>
                    <dt class="fi-fo-field-label-content">{{ __('submission_panel.columns.activity') }}</dt>
                    <dd style="margin: 0; overflow-wrap: anywhere;">
                        <a href="{{ $activityLink['url'] }}" class="fi-link">{{ $activityLink['title'] }}</a>
                    </dd>
                </div>
            @endif
            <div>
                <dt class="fi-fo-field-label-content">{{ __('submission_panel.columns.submitted_at') }}</dt>
                <dd style="margin: 0;">{{ $submittedAt }}</dd>
            </div>
        </dl>
    </x-filament::section>

    <x-filament::section :heading="__('submission_panel.detail.answers')">
        @forelse ($detail['answers'] as $answer)
            <div style="margin-bottom: 1rem;">
                <div class="fi-fo-field-label-content">
                    {{ $answer['label'] }}
                    @if ($answer['retired'])
                        <span>({{ __('submission_panel.detail.retired') }})</span>
                    @endif
                </div>

                @if ($answer['type'] === 'file')
                    @if ($detail['canDownload'])
                        <ul style="margin: 0; padding-left: 1.25rem;">
                            @foreach ($answer['files'] as $file)
                                <li>
                                    @if ($file['previewUrl'] !== null)
                                        <a href="{{ $file['previewUrl'] }}" target="_blank" rel="noopener" style="display: block; margin-bottom: 0.5rem;">
                                            <img src="{{ $file['previewUrl'] }}" alt="{{ $file['name'] }}" loading="lazy" style="max-width: 100%; max-height: 24rem; border-radius: 0.5rem; border: 1px solid rgb(0 0 0 / 0.1);">
                                        </a>
                                    @endif
                                    <a href="{{ $file['url'] }}" class="fi-link" rel="noopener" style="overflow-wrap: anywhere;">{{ $file['name'] }}</a>
                                    <span>({{ $file['size'] }})</span>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p style="margin: 0;">{{ trans_choice('submission_panel.detail.files_attached', $answer['fileCount'], ['count' => $answer['fileCount']]) }}</p>
                    @endif
                @elseif (is_bool($answer['value']))
                    <p style="margin: 0;">{{ $answer['value'] ? __('submission_panel.detail.yes') : __('submission_panel.detail.no') }}</p>
                @elseif (is_array($answer['value']))
                    <ul style="margin: 0; padding-left: 1.25rem;">
                        @foreach ($answer['value'] as $item)
                            <li>{{ $item }}</li>
                        @endforeach
                    </ul>
                @else
                    <p style="margin: 0; white-space: pre-line; overflow-wrap: anywhere;">{{ $answer['value'] }}</p>
                @endif
            </div>
        @empty
            <p style="margin: 0;">{{ __('submission_panel.detail.no_answers') }}</p>
        @endforelse
    </x-filament::section>

    <x-filament::section :heading="__('submission_panel.detail.participants')">
        @forelse ($detail['participants'] as $participant)
            <div style="margin-bottom: 1.5rem; overflow-wrap: anywhere; display: flex; gap: 1rem; align-items: flex-start;">
                @if ($participant['photoUrl'] !== null)
                    <a href="{{ $participant['photoUrl'] }}" target="_blank" rel="noopener" style="flex: none;">
                        <img src="{{ $participant['photoUrl'] }}" alt="{{ $participant['name'] }}" loading="lazy" style="width: 6rem; height: 6rem; object-fit: cover; border-radius: 9999px; border: 1px solid rgb(0 0 0 / 0.1);">
                    </a>
                @endif
                <div style="min-width: 0; flex: 1;">
                    <strong>{{ $participant['name'] }}</strong>
                    @if ($participant['primary'])
                        <x-filament::badge>{{ __('submission_panel.detail.primary') }}</x-filament::badge>
                    @endif
                    <dl style="display: grid; grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr)); gap: 0.5rem 1rem; margin: 0.5rem 0 0;">
                        @foreach ($participant['fields'] as $field)
                            <div>
                                <dt class="fi-fo-field-label-content">{{ $field['label'] }}</dt>
                                <dd style="margin: 0; white-space: pre-line;">{{ $field['value'] }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            </div>
        @empty
            <p style="margin: 0;">{{ __('submission_panel.detail.no_participants') }}</p>
        @endforelse
    </x-filament::section>
</div>
