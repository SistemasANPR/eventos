@php
    /**
     * Detalle de la propuesta asignada al evaluador (plan 011, H-011-3). TODO el texto viene del usuario: se
     * imprime solo con `{{ }}` (escapado). Nunca `{!! !!}` ni `@js` de contenido. Los datos salen de
     * `SubmissionDetail::forReviewer` (archivos con nombre neutro); la revisión ciega se aplica allí, en el servidor
     * (CA-011-18 y 19): esta vista no decide nada, solo imprime lo que recibió y explica la ausencia de autores.
     *
     * @var \App\Models\ReviewAssignment $assignment
     * @var \App\Models\Submission $submission
     * @var \App\Models\Event $event
     * @var array{answers: list<array<string, mixed>>, participants: list<array<string, mixed>>, canDownload: bool, blind: bool} $detail
     */
@endphp

<div class="fi-reviewer-assignment-detail" style="display: grid; gap: 1.5rem;">
    <x-filament::section :heading="__('submission_panel.detail.general')">
        <dl style="display: grid; grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr)); gap: 1rem; margin: 0;">
            <div>
                <dt class="fi-fo-field-label-content">{{ __('reviewer.list.columns.title') }}</dt>
                <dd style="margin: 0; overflow-wrap: anywhere;">{{ $submission->title }}</dd>
            </div>
            <div>
                <dt class="fi-fo-field-label-content">{{ __('reviewer.list.columns.status') }}</dt>
                <dd style="margin: 0;">
                    <x-filament::badge :color="$assignment->status->getColor()">{{ $assignment->status->getLabel() }}</x-filament::badge>
                </dd>
            </div>
            <div>
                <dt class="fi-fo-field-label-content">{{ __('reviewer.list.columns.event') }}</dt>
                <dd style="margin: 0; overflow-wrap: anywhere;">{{ $event->getTranslation('name', app()->getLocale(), true) }}</dd>
            </div>
            <div>
                <dt class="fi-fo-field-label-content">{{ __('submission_panel.columns.type') }}</dt>
                <dd style="margin: 0;">{{ $submission->submissionType->label(app()->getLocale()) }}</dd>
            </div>
            <div>
                <dt class="fi-fo-field-label-content">{{ __('reviewer.list.columns.track') }}</dt>
                <dd style="margin: 0;">{{ $submission->track->getTranslation('name', app()->getLocale(), true) }}</dd>
            </div>
            @if ($submission->call !== null)
                <div>
                    <dt class="fi-fo-field-label-content">{{ __('reviewer.list.columns.call') }}</dt>
                    <dd style="margin: 0;">{{ $submission->call->getTranslation('title', app()->getLocale(), true) }}</dd>
                </div>
            @endif
        </dl>
    </x-filament::section>

    <x-filament::section :heading="__('submission_panel.detail.answers')">
        @forelse ($detail['answers'] as $answer)
            <div style="margin-bottom: 1rem;">
                <div class="fi-fo-field-label-content">{{ $answer['label'] }}</div>

                @if ($answer['type'] === 'file')
                    @if ($detail['canDownload'])
                        <ul style="margin: 0; padding-left: 1.25rem;">
                            @foreach ($answer['files'] as $file)
                                <li>
                                    <a href="{{ $file['url'] }}" class="fi-link" rel="noopener" style="overflow-wrap: anywhere;">{{ $file['name'] }}</a>
                                    <span>({{ $file['size'] }})</span>
                                </li>
                            @endforeach
                        </ul>
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
        @if ($detail['blind'])
            {{-- Revisión ciega: el servidor no entregó a los autores; esto solo explica por qué no hay nada. --}}
            <p role="status" style="margin: 0;">{{ __('reviewer_integrity.blind.notice') }}</p>
        @else
        @forelse ($detail['participants'] as $participant)
            <div style="margin-bottom: 1rem; overflow-wrap: anywhere;">
                <strong>{{ $participant['name'] }}</strong>
                @if ($participant['primary'])
                    <x-filament::badge>{{ __('submission_panel.detail.primary') }}</x-filament::badge>
                @endif
                <div>{{ $participant['email'] }}</div>
                <div>
                    {{ collect([$participant['jobTitle'], $participant['company'], $participant['country']])->filter()->implode(' · ') }}
                </div>
                @if ($participant['bio'])
                    <p style="margin: 0.25rem 0 0; white-space: pre-line;">{{ $participant['bio'] }}</p>
                @endif
            </div>
        @empty
            <p style="margin: 0;">{{ __('submission_panel.detail.no_participants') }}</p>
        @endforelse
        @endif
    </x-filament::section>
</div>
