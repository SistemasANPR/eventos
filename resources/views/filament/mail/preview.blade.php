@php
    /** @var array{subject: string, html: string} $preview */
@endphp

{{-- Vista previa de una plantilla con datos de ejemplo (plan 008, CA-008-16). El HTML se muestra
     dentro de un iframe con `sandbox` (sin scripts ni formularios), aunque ya viene escapado. --}}
<x-filament::section :heading="__('mail_admin.edit.preview.heading')" :description="__('mail_admin.edit.preview.note')">
    <p style="margin-bottom: 0.75rem;">
        <strong>{{ __('mail_admin.edit.preview.subject') }}:</strong>
        {{ $preview['subject'] }}
    </p>
    <iframe
        sandbox
        title="{{ __('mail_admin.edit.preview.heading') }}"
        srcdoc="{{ $preview['html'] }}"
        style="width: 100%; min-height: 20rem; border: 1px solid #d4d4d8; border-radius: 0.5rem; background: #fff;"
    ></iframe>
</x-filament::section>
