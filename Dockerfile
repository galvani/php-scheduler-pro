FROM php:8.5-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/* \
    && pecl install redis \
    && docker-php-ext-enable redis

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Mount points for the named vendor volumes; the services run as the host user, so they must be writable.
RUN mkdir -p /app/vendor /app/demo/symfony/vendor /app/demo/symfony/var /app/demo/laravel/vendor \
    && chmod -R 0777 /app
