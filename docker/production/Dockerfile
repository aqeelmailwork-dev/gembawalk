# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Stage 1: "vendor" — production PHP dependencies.
# Runs first because the "assets" stage below needs vendor/ (resources/css/app.css
# @imports livewire/flux CSS from inside vendor/).
# ---------------------------------------------------------------------------
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader \
    --no-scripts \
    --ignore-platform-reqs

# ---------------------------------------------------------------------------
# Stage 2: "assets" — build the front-end bundle with Vite.
# ---------------------------------------------------------------------------
FROM node:22-alpine AS assets

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY vite.config.js ./
COPY resources/ ./resources/
COPY public/ ./public/
# app.css pulls in Flux's CSS (and a couple of vendor @source paths), so vendor/
# has to be present for the Tailwind/Vite build.
COPY --from=vendor /app/vendor ./vendor

RUN npm run build

# ---------------------------------------------------------------------------
# Stage 3: "final" — serversideup PHP 8.4 (FPM + nginx), non-root.
# ---------------------------------------------------------------------------
FROM serversideup/php:8.4-fpm-nginx AS final

# intervention/image needs gd + exif; intl and bcmath are common Laravel needs.
USER root
RUN install-php-extensions gd exif intl bcmath

# nginx reads client_max_body_size $NGINX_CLIENT_MAX_BODY_SIZE (default 100M);
# it is NOT derived from PHP_POST_MAX_SIZE. Livewire sends all selected gallery
# photos in ONE request (up to 20 x 12 MB), so 256M here is what keeps nginx from
# rejecting that upload with HTTP 413.
ENV NGINX_CLIENT_MAX_BODY_SIZE=256M \
    PHP_UPLOAD_MAX_FILE_SIZE=16M \
    PHP_POST_MAX_SIZE=256M \
    PHP_MEMORY_LIMIT=512M \
    PHP_MAX_EXECUTION_TIME=120 \
    PHP_OPCACHE_ENABLE=1 \
    AUTORUN_ENABLED=true

USER www-data
WORKDIR /var/www/html

# App source, then vendor and the built assets layered on top.
COPY --chown=www-data:www-data . /var/www/html
COPY --from=vendor --chown=www-data:www-data /app/vendor /var/www/html/vendor
COPY --from=assets --chown=www-data:www-data /app/public/build /var/www/html/public/build

# The vendor stage generated the autoloader against /app; regenerate it against
# /var/www/html and run package discovery. Composer is borrowed from the
# composer image for this step, then removed again so it is not shipped in the
# final image.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN composer dump-autoload --optimize --no-dev --no-interaction --no-scripts \
    && php artisan package:discover --ansi

USER root
RUN rm -f /usr/bin/composer
USER www-data

# storage/ and bootstrap/cache are already owned by www-data via the COPY
# --chown above, so Laravel can write cache, sessions, views and logs at runtime.
# (storage/app is backed by the gembawalk-storage named volume at runtime.)
