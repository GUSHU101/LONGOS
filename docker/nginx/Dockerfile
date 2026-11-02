# syntax=docker/dockerfile:1.7

FROM composer:2.8.12 AS composer-binary

# Build Composer dependencies against the exact PHP runtime and extensions
# used by production. The plain Composer image intentionally has only a small
# extension set; using it as the PHP platform caused valid locked packages
# such as Horizon and PhpSpreadsheet to fail on ext-pcntl/ext-gd even though
# those extensions were present in the final application image.
FROM php:8.2.34-fpm-bookworm AS runtime-base

ARG PHP_BUILD_JOBS=1
ARG APT_RETRIES=5
ARG PECL_RETRIES=3

# Keep the operating-system download separate from extension compilation so a
# transient mirror failure does not discard several minutes of compiled PHP
# work. IPv4 avoids slow broken IPv6 paths on smaller VPS providers, while the
# retry/timeout policy still fails closed instead of accepting unsigned data.
RUN set -eux; \
    apt-get \
        -o Acquire::Retries="${APT_RETRIES}" \
        -o Acquire::ForceIPv4=true \
        -o Acquire::http::Timeout=60 \
        -o Acquire::https::Timeout=60 \
        update; \
    DEBIAN_FRONTEND=noninteractive apt-get \
        -o Acquire::Retries="${APT_RETRIES}" \
        -o Acquire::ForceIPv4=true \
        -o Acquire::http::Timeout=60 \
        -o Acquire::https::Timeout=60 \
        install -y --no-install-recommends \
    $PHPIZE_DEPS \
    nginx \
    curl \
    ca-certificates \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libicu-dev \
    libmagickwand-dev \
    libwebp-dev \
    libpq-dev \
    libzip-dev \
    unzip \
    util-linux; \
    rm -rf /var/lib/apt/lists/*

# One compile job is deliberately the default: Coolify, BuildKit, MySQL and
# the running storefront commonly share a one-vCPU host. Operators with a
# dedicated build machine can still override PHP_BUILD_JOBS explicitly.
RUN set -eux; \
    docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp; \
    docker-php-ext-install -j"${PHP_BUILD_JOBS}" \
        pdo_mysql \
        pdo_pgsql \
        exif \
        pcntl \
        bcmath \
        gd \
        intl \
        zip \
        opcache

# Imagick remains required for animated and extended WebP sources. Isolating
# PECL in its own cached layer makes both failures and retries unambiguous.
RUN --mount=type=cache,target=/tmp/pear/cache,sharing=locked \
    set -eux; \
    pecl config-set cache_dir /tmp/pear/cache; \
    pecl_work_dir="$(mktemp -d)"; \
    pecl_package="${pecl_work_dir}/imagick-3.7.0.tgz"; \
    pecl_attempt=1; \
    until (cd "${pecl_work_dir}" && pecl download imagick-3.7.0); do \
        rm -f "${pecl_package}"; \
        if [ "${pecl_attempt}" -ge "${PECL_RETRIES}" ]; then \
            echo "Unable to download imagick-3.7.0 after ${PECL_RETRIES} attempts" >&2; \
            exit 1; \
        fi; \
        pecl_attempt=$((pecl_attempt + 1)); \
        sleep $((pecl_attempt * 5)); \
    done; \
    MAKEFLAGS="-j${PHP_BUILD_JOBS}" pecl install "${pecl_package}"; \
    rm -rf "${pecl_work_dir}"; \
    docker-php-ext-enable imagick; \
    php --ri imagick > /dev/null

RUN set -eux; \
    php -r '$required = ["pdo_mysql", "pdo_pgsql", "curl", "mbstring", "exif", "pcntl", "posix", "bcmath", "gd", "imagick", "intl", "dom", "simplexml", "xml", "xmlreader", "xmlwriter", "zip", "Zend OPcache"]; $missing = array_values(array_filter($required, static fn (string $extension): bool => !extension_loaded($extension))); if ($missing !== []) { fwrite(STDERR, "Missing required PHP extensions: " . implode(", ", $missing) . PHP_EOL); exit(1); }'; \
    rm -f /etc/nginx/sites-enabled/default /etc/nginx/sites-available/default; \
    mkdir -p /run/nginx /var/log/nginx /var/log/php

FROM runtime-base AS php-dependencies

WORKDIR /build

COPY --from=composer-binary /usr/bin/composer /usr/local/bin/composer

COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/tmp/cache/composer \
    COMPOSER_CACHE_DIR=/tmp/cache/composer composer install \
      --no-dev \
      --no-interaction \
      --no-progress \
      --prefer-dist \
      --no-autoloader \
      --no-scripts

# Generate the optimized project class map only after every root PSR-4/files
# path exists. Dependency downloads still remain cached by the lockfile-only
# layer above.
COPY app ./app
COPY beike ./beike
COPY database ./database
COPY plugins ./plugins
RUN composer dump-autoload \
      --no-dev \
      --no-interaction \
      --optimize \
      --no-scripts

FROM node:22.22.0-bookworm-slim AS browser-assets

WORKDIR /build

# Keep the immutable frontend build inside a predictable memory envelope on
# smaller single-node Coolify servers where the control plane, BuildKit and
# MySQL share RAM. This is a ceiling, not a reservation.
ENV NODE_OPTIONS=--max-old-space-size=1536

COPY package.json package-lock.json ./
RUN --mount=type=cache,target=/root/.npm npm ci --ignore-scripts

COPY . .
RUN npm run production

FROM runtime-base AS runtime

ENV APP_ENV=production \
    APP_DEBUG=false \
    HOME=/var/www \
    XDG_CACHE_HOME=/var/www/.cache

WORKDIR /var/www/html

COPY . .
COPY --from=php-dependencies /build/vendor ./vendor
COPY --from=browser-assets /build/public ./public
COPY docker/nginx/nginx-site.conf /etc/nginx/conf.d/default.conf
COPY docker/nginx/php-production.ini /usr/local/etc/php/conf.d/zz-longos-production.ini
COPY docker/nginx/start.sh /usr/local/bin/longos-entrypoint

RUN chmod 0755 /usr/local/bin/longos-entrypoint \
    && mkdir -p \
       storage/app/private \
       storage/framework/cache/data \
       storage/framework/cache/htmlpurifier \
       storage/framework/sessions \
       storage/framework/views \
       storage/logs \
       bootstrap/cache \
       public/cache \
       public/image/catalog \
       public/plugin \
       public/upload \
       /var/www/.cache/fontconfig \
    && mkdir -p /opt/longos-seed \
    && cp -a plugins /opt/longos-seed/plugins \
    && cp -a public/plugin /opt/longos-seed/public-plugin \
    && find storage bootstrap/cache public/cache public/image/catalog public/plugin public/upload \
       -type d -exec chmod 0775 {} + \
    && chown -R www-data:www-data /var/www/.cache

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/longos-entrypoint"]
CMD ["web"]

