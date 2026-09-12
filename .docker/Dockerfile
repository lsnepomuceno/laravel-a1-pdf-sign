# Development / CI image for laravel-a1-pdf-sign.
#
# The PHP version is parameterized so any cell of the CI matrix can be
# reproduced locally. The development host does not need the target version
# installed.
ARG PHP_VERSION=8.4

FROM php:${PHP_VERSION}-cli-alpine

# openssl: reading PKCS#12 bundles, and the binary the engine shells out to
# gd / imagick: seal rendering (Intervention Image), through signet-pdf
# bcmath: required by tc-lib-pdf through tc-lib-barcode, a transitive dependency
# pcov: coverage driver
#
# **No verification instruments.** veraPDF, qpdf, pyHanko and the Arlington
# model measure written bytes, and this package writes none: the engine lives
# in lsnepomuceno/signet-pdf and the instruments live with it
# (docs/decisions/0039-the-core-lives-in-signet-pdf.md). Removing them takes a
# headless JRE, a Python venv, a C++ build toolchain and roughly 700 MB out of
# the image.
RUN apk add --no-cache \
        git unzip libzip-dev libpng-dev libjpeg-turbo-dev freetype-dev \
        imagemagick imagemagick-dev $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" gd zip bcmath \
    && pecl install pcov imagick \
    && docker-php-ext-enable pcov imagick \
    && apk del $PHPIZE_DEPS \
    && rm -rf /tmp/pear

# Static analysis needs far more than the 128M CLI default.
RUN printf 'memory_limit = 1G\n' > /usr/local/etc/php/conf.d/zz-dev.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_MEMORY_LIMIT=-1

# The bind-mounted checkout belongs to the host user, so git inside the
# container refuses to read it and Composer loses the package version.
RUN git config --global --add safe.directory /app

WORKDIR /app
