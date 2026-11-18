#!/bin/sh
# Arranque común de web, worker y scheduler. NO aplica migraciones: las aplica una persona (AGENTS.md, regla 1).
set -e

# Cachés que dependen de las variables de entorno reales; se calculan al arrancar, no al construir la imagen.
php artisan config:cache
php artisan view:cache
php artisan event:cache

exec "$@"
