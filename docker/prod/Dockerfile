# syntax=docker/dockerfile:1

##### Stage 1: Composer dependencies #####
FROM composer:2 AS composer_stage

WORKDIR /app

COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --ignore-platform-reqs \
    --prefer-dist \
    --no-progress

COPY . .

RUN composer dump-autoload --optimize --no-dev --classmap-authoritative

##### Stage 2: Runtime image #####
FROM php:8.4-fpm-alpine AS app

# System dependencies + PHP extensions needed by this project's composer.json
# (ext-curl, ext-ctype, ext-iconv, ext-openssl required; ctype/iconv/openssl are
# already built into the php-alpine base, so only curl + pgsql + common Symfony
# extensions need to be added explicitly)
RUN apk add --no-cache \
        icu-dev \
        icu-libs \
        libzip-dev \
        libzip \
        postgresql-dev \
        libpq \
        libxml2-dev \
        libxml2 \
        oniguruma-dev \
        oniguruma \
        curl-dev \
        libcurl \
        git \
        unzip \
        rsync \
    && docker-php-ext-install -j$(nproc) \
        pdo \
        pdo_pgsql \
        intl \
        zip \
        opcache \
        curl \
        xml \
        mbstring \
    && apk del --no-cache postgresql-dev libxml2-dev libzip-dev icu-dev oniguruma-dev curl-dev \
    && apk add --no-cache --virtual .phpredis-deps $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del --no-cache .phpredis-deps

# Recommended php.ini settings for prod
RUN { \
        echo 'opcache.memory_consumption=256'; \
        echo 'opcache.interned_strings_buffer=16'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'opcache.enable_cli=1'; \
        echo 'realpath_cache_size=4096K'; \
        echo 'realpath_cache_ttl=600'; \
        echo 'memory_limit=256M'; \
    } > /usr/local/etc/php/conf.d/opcache-recommended.ini

WORKDIR /app

# Copy app + vendor from composer stage
COPY --from=composer_stage /app /app

# .env minimal requis par Symfony bootEnv — les vraies valeurs viennent des env vars Docker
RUN echo "APP_ENV=prod" > /app/.env

# Dossiers writables + point de montage pour assets partagés avec nginx
RUN mkdir -p /app/var /app/public_shared \
    && chown -R www-data:www-data /app/var /app/public_shared

COPY docker/prod/php/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

USER www-data

EXPOSE 9000

ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]
