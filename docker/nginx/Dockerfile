# syntax=docker/dockerfile:1.7

FROM composer:2.8.12 AS composer-binary

# Build Composer dependencies against the exact PHP runtime and extensions
# used by production. The plain Composer image intentionally has only a small
# extension set; using it as the PHP platform caused valid locked packages
# such as Horizon and PhpSpreadsheet to fail on ext-pcntl/ext-gd even though
# those extensions were present in the final application image.
FROM php:8.2.34-fpm-bookworm AS runtime-base

ARG PHP_BUILD_JOBS=2

RUN apt-get update && apt-get install -y --no-install-recommends \
    nginx \
    curl \
    ca-certificates \
    libcurl4-openssl-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libicu-dev \
    libwebp-dev \
    libonig-dev \
    libpq-dev \
    libxml2-dev \
    libzip-dev \
    unzip \
    util-linux \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"${PHP_BUILD_JOBS}" \
        pdo_mysql \
        pdo_pgsql \
        exif \
        pcntl \
        bcmath \
        gd \
        intl \
        zip \
        opcache \
    && php -r '$required = ["pdo_mysql", "pdo_pgsql", "curl", "mbstring", "exif", "pcntl", "posix", "bcmath", "gd", "intl", "dom", "simplexml", "xml", "xmlreader", "xmlwriter", "zip", "Zend OPcache"]; $missing = array_values(array_filter($required, static fn (string $extension): bool => !extension_loaded($extension))); if ($missing !== []) { fwrite(STDERR, "Missing required PHP extensions: " . implode(", ", $missing) . PHP_EOL); exit(1); }' \
    && rm -rf /var/lib/apt/lists/* \
    && rm -f /etc/nginx/sites-enabled/default /etc/nginx/sites-available/default \
    && mkdir -p /run/nginx /var/log/nginx /var/log/php

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
    APP_DEBUG=false

WORKDIR /var/www/html

COPY . .
COPY --from=php-dependencies /build/vendor ./vendor
COPY --from=browser-assets /build/public ./public
COPY docker/nginx/nginx-site.conf /etc/nginx/conf.d/default.conf
COPY docker/nginx/start.sh /usr/local/bin/longos-entrypoint

RUN chmod 0755 /usr/local/bin/longos-entrypoint \
    && mkdir -p \
       storage/app/private \
       storage/framework/cache/data \
       storage/framework/sessions \
       storage/framework/views \
       storage/logs \
       bootstrap/cache \
       public/cache \
       public/image/catalog \
       public/plugin \
       public/upload \
    && mkdir -p /opt/longos-seed \
    && cp -a plugins /opt/longos-seed/plugins \
    && cp -a public/plugin /opt/longos-seed/public-plugin \
    && find storage bootstrap/cache public/cache public/image/catalog public/plugin public/upload \
       -type d -exec chmod 0775 {} +

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/longos-entrypoint"]
CMD ["web"]

