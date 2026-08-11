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
# qpdf: structural validation of what the revision writer emits. Unlike
#       veraPDF it is C++ and a couple of megabytes, so it belongs in the
#       everyday image (docs/spec/quality-policy.md).
RUN apk add --no-cache \
        git unzip libzip-dev libpng-dev libjpeg-turbo-dev freetype-dev \
        imagemagick imagemagick-dev qpdf $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" gd zip bcmath \
    && pecl install pcov imagick \
    && docker-php-ext-enable pcov imagick \
    && apk del $PHPIZE_DEPS \
    && rm -rf /tmp/pear

# Static analysis needs far more than the 128M CLI default.
RUN printf 'memory_limit = 1G\n' > /usr/local/etc/php/conf.d/zz-dev.ini

# veraPDF, the reference PDF/A validator, off by default.
#
# It is Java, so installing it costs a headless JRE on top of its own 33 MB, and
# the day-to-day image should not carry that for a check that runs on the PDF/A
# group alone. The `pdfa` compose service turns it on; everything else does not.
#
# Pinned rather than tracking "latest": a validator that changes its verdicts
# between builds cannot be the thing a gate is measured against.
# See docs/decisions/0025-what-signing-does-to-pdf-a.md.
ARG WITH_VERAPDF=false
ARG VERAPDF_VERSION=1.30.2

RUN if [ "$WITH_VERAPDF" = "true" ]; then \
        set -eux; \
        apk add --no-cache openjdk17-jre-headless curl; \
        curl -sSL -o /tmp/verapdf.zip \
            "https://software.verapdf.org/releases/${VERAPDF_VERSION%.*}/verapdf-greenfield-${VERAPDF_VERSION}-installer.zip"; \
        unzip -q /tmp/verapdf.zip -d /tmp/verapdf-src; \
        printf '%s\n' \
            '<?xml version="1.0" encoding="UTF-8" standalone="no"?>' \
            '<AutomatedInstallation langpack="eng">' \
            '<com.izforge.izpack.panels.htmlhello.HTMLHelloPanel id="welcome"/>' \
            '<com.izforge.izpack.panels.target.TargetPanel id="install_dir">' \
            '<installpath>/opt/verapdf</installpath>' \
            '</com.izforge.izpack.panels.target.TargetPanel>' \
            '<com.izforge.izpack.panels.packs.PacksPanel id="sdk_pack_select">' \
            '<pack index="0" name="veraPDF GUI" selected="true"/>' \
            '<pack index="1" name="veraPDF Mac and *nix Scripts" selected="true"/>' \
            '<pack index="3" name="veraPDF Corpus and Tests" selected="false"/>' \
            '<pack index="4" name="veraPDF Documentation" selected="false"/>' \
            '<pack index="5" name="veraPDF Sample Plugins" selected="false"/>' \
            '</com.izforge.izpack.panels.packs.PacksPanel>' \
            '<com.izforge.izpack.panels.install.InstallPanel id="install"/>' \
            '<com.izforge.izpack.panels.finish.FinishPanel id="finish"/>' \
            '</AutomatedInstallation>' > /tmp/verapdf-auto.xml; \
        java -jar /tmp/verapdf-src/verapdf-greenfield-*/verapdf-izpack-installer-*.jar /tmp/verapdf-auto.xml; \
        ln -s /opt/verapdf/verapdf /usr/local/bin/verapdf; \
        rm -rf /tmp/verapdf.zip /tmp/verapdf-src /tmp/verapdf-auto.xml; \
        verapdf --version; \
    fi

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_MEMORY_LIMIT=-1

# The bind-mounted checkout belongs to the host user, so git inside the
# container refuses to read it and Composer loses the package version.
RUN git config --global --add safe.directory /app

WORKDIR /app
