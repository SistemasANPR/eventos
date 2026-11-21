#!/bin/sh
# Arranque común de web, worker y scheduler. NO aplica migraciones: las aplica una persona (AGENTS.md, regla 1).
set -e

# Cachés que dependen de las variables de entorno reales; se calculan al arrancar, no al construir la imagen.
php artisan config:cache
php artisan view:cache
php artisan event:cache

# Opcional (RUN_MIGRATIONS=true): aplica las migraciones pendientes al arrancar el proceso web, para no tener
# que entrar al servidor tras cada despliegue. Solo en el proceso web (no en worker ni scheduler). Si una
# migración falla, el contenedor no arranca y Railway conserva la versión anterior. Es una decisión de quien
# administra el entorno: se activa a propósito y se deja apagado en producción si se prefiere aprobar a mano.
if [ "${RUN_MIGRATIONS:-false}" = "true" ] && [ "$1" = "frankenphp" ]; then
    php artisan migrate --force
fi

# Opcional (RUN_QUEUE_WORKER=true): procesa la cola de correos dentro del mismo contenedor web, para un
# despliegue de un solo servicio. Solo en el proceso web; un servicio `worker` aparte no lo necesita. El
# bucle lo reinicia cuando `--max-time` lo detiene.
if [ "${RUN_QUEUE_WORKER:-false}" = "true" ] && [ "$1" = "frankenphp" ]; then
    (
        while true; do
            php artisan queue:work --tries=3 --max-time=3600 --sleep=3 || true
            sleep 2
        done
    ) &
fi

exec "$@"
