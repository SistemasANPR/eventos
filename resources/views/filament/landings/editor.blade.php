{{-- Editor visual de la landing del evento (plan 022, F4): monta el LandingEditor de React y le da su persistencia por Livewire. --}}
@if (! $available)
    {{-- El componente de React aún no existe en esta compilación: se dice, no se finge. --}}
    <p class="text-sm text-gray-600" role="status">{{ __('landings.editor.unavailable') }}</p>
@else
    {{-- wire:ignore: React es el único dueño del estado; Livewire solo guarda. --}}
    <div
        wire:ignore
        data-landing-editor
        data-props="{{ json_encode($props, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) }}"
        x-data="{
            tries: 0,
            api() {
                const wire = this.$wire;

                return {
                    saveDraft: (blocks, version) => wire.saveDraft(blocks, version),
                    publish: (blocks, version) => wire.publish(blocks, version),
                    discard: () => wire.discard(),
                    uploadImage: (file) => new Promise((resolve, reject) => {
                        wire.upload('imageUpload', file, () => wire.uploadImage().then(resolve, reject), () => reject(new Error('upload')));
                    }),
                };
            },
            mount() {
                if (window.mountLandingEditor) {
                    window.mountLandingEditor(this.$el, this.api());
                } else if (this.tries++ < 100) {
                    setTimeout(() => this.mount(), 50);
                }
            },
        }"
        x-init="mount()"
    ></div>

    {{-- Con el servidor de desarrollo, @vitejs/plugin-react exige el preámbulo de refresco antes de la entrada; sin él React no se monta. --}}
    @viteReactRefresh
    @vite('resources/js/landing-editor.tsx')
@endif
