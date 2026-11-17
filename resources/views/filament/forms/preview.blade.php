{{-- Vista previa del formulario (plan 005, H-005-2): monta el FormRenderer de React. --}}
<div class="space-y-4">
    <div class="flex flex-wrap items-center gap-2" role="group" aria-label="{{ __('forms.preview.language') }}">
        <span class="text-sm text-gray-600">{{ __('forms.preview.language') }}</span>
        @foreach ($locales as $code)
            <x-filament::button
                size="sm"
                :color="$code === $locale ? 'primary' : 'gray'"
                :outlined="$code !== $locale"
                :aria-pressed="$code === $locale ? 'true' : 'false'"
                wire:click="$set('locale', '{{ $code }}')"
            >
                {{ strtoupper($code) }}
            </x-filament::button>
        @endforeach
    </div>

    {{-- La clave cambia con el idioma: Livewire crea un elemento nuevo y React se monta de nuevo. --}}
    <div
        wire:key="form-preview-{{ $locale }}"
        wire:ignore
        data-form-preview
        data-props="{{ json_encode($props, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) }}"
        x-data
        x-init="window.mountFormPreview?.($el)"
    ></div>
</div>

@vite('resources/js/form-preview.tsx')
