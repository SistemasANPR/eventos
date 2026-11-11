{{--
    Aviso amable para el 429 del límite de acceso (plan 001, CA-001-37).
    El servidor sigue respondiendo 429 con `Retry-After`; Livewire mostraría su
    modal de error genérico, así que se intercepta el error en el cliente y se
    sustituye por una notificación de Filament (región `aria-live`). El texto no
    depende de si el correo existe: solo informa el tiempo de espera.
--}}
<script>
    document.addEventListener('livewire:init', () => {
        const messages = {
            title: @js(__('access.throttled.title')),
            one: @js(__('access.throttled.body_one')),
            other: @js(__('access.throttled.body_other')),
        };

        Livewire.interceptRequest(({ onError }) => {
            onError(({ response, preventDefault }) => {
                if (response.status !== 429) {
                    return;
                }

                preventDefault();

                const seconds = Number.parseInt(response.headers.get('Retry-After') ?? '', 10);
                const minutes = Math.max(1, Math.ceil((Number.isFinite(seconds) ? seconds : 60) / 60));

                new window.FilamentNotification()
                    .title(messages.title)
                    .body((minutes === 1 ? messages.one : messages.other).replace(':minutes', String(minutes)))
                    .warning()
                    .seconds(8)
                    .send();
            });
        });
    });
</script>
