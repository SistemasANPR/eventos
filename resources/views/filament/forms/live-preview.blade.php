{{-- «Así se ve» del editor de formularios: solo el bloque de Participantes, con el límite de personas que se está editando. --}}
<div class="space-y-3">
    <p class="text-sm text-gray-600">{{ __('forms.preview.live_notice') }}</p>

    {{-- La clave cambia con el límite: Livewire crea un elemento nuevo y React se monta de nuevo. --}}
    <div
        wire:key="participants-preview-{{ $props['maxParticipants'] }}"
        wire:ignore
        data-participants-preview
        data-props="{{ json_encode($props, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) }}"
        x-data
        x-init="window.mountParticipantsPreview?.($el)"
    ></div>
</div>

@viteReactRefresh
@vite('resources/js/form-preview.tsx')
