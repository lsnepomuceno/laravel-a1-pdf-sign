# Development / CI image for laravel-a1-pdf-sign.
#
# The PHP version is parameterized so any cell of the CI matrix can be
# reproduced locally (see ARCHITECTURE-V2.md §3e.2). The development host does
# not need the target version installed.
ARG PHP_VERSION=8.4

FROM php:${PHP_VERSION}-cli-alpine

# openssl: PKCS#7 signing and PKCS#12 reading
# gd / imagick: seal rendering (Intervention Image)
# bcmath: required by tc-lib-pdf through tc-lib-barcode
# pcov: fast coverage driver, required by mutation testing
RUN apk add --no-cache \
        git unzip libzip-dev libpng-dev libjpeg-turbo-dev freetype-dev \
        imagemagick imagemagick-dev $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" gd zip bcmath \
    && pecl install pcov imagick \
    && docker-php-ext-enable pcov imagick \
    && apk del $PHPIZE_DEPS \
    && rm -rf /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_MEMORY_LIMIT=-1

WORKDIR /app
