# syntax=docker/dockerfile:1
#
# Imagen de PRODUCCIÓN y staging (Railway). El entorno local usa Sail (compose.yaml), no este archivo.
# Un mismo contenido sirve a los tres servicios de Railway; cambia solo el comando de arranque:
#   web:       (el predeterminado) FrankenPHP escuchando en $PORT
#   worker:    php artisan queue:work --tries=3 --max-time=3600
#   scheduler: php artisan schedule:work

# 1) Dependencias de PHP, sin las de desarrollo.
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --no-progress --ignore-platform-reqs

# 2) Recursos del frontend. El tema del panel importa el CSS de Filament desde vendor/, por eso se copia.
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
COPY --from=vendor /app/vendor ./vendor
RUN npm run build

# 3) Imagen final.
FROM dunglas/frankenphp:1-php8.4 AS app
RUN install-php-extensions pdo_pgsql pgsql redis gd intl zip bcmath opcache pcntl exif
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build

RUN composer dump-autoload --optimize --no-dev --no-interaction \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chmod -R ug+rwx storage bootstrap/cache

COPY docker/production/Caddyfile /etc/caddy/Caddyfile
COPY docker/production/start.sh /usr/local/bin/start.sh
RUN chmod +x /usr/local/bin/start.sh

ENV APP_ENV=production APP_DEBUG=false LOG_CHANNEL=stderr
ENTRYPOINT ["/usr/local/bin/start.sh"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile", "--adapter", "caddyfile"]
