{{-- Vista previa en vivo del editor de formularios: monta el FormRenderer de React con lo que hay en pantalla. --}}
<div class="space-y-3">
    <p class="text-sm text-gray-600">{{ __('forms.preview.live_notice') }}</p>

    {{-- La clave cambia con el contenido: Livewire crea un elemento nuevo y React se monta de nuevo. --}}
    <div
        wire:key="form-live-preview-{{ md5(json_encode($props, JSON_UNESCAPED_UNICODE) ?: '') }}"
        wire:ignore
        data-form-preview
        data-props="{{ json_encode($props, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) }}"
        x-data
        x-init="window.mountFormPreview?.($el)"
    ></div>
</div>

@viteReactRefresh
@vite('resources/js/form-preview.tsx')
