FROM php:8.2-cli-bookworm

LABEL org.opencontainers.image.title="MPAY"
LABEL org.opencontainers.image.description="MPAY Webman payment gateway with built-in Alipay OpenAPI bill watcher"

ENV DEBIAN_FRONTEND=noninteractive \
    TZ=Asia/Shanghai \
    COMPOSER_ALLOW_SUPERUSER=1 \
    EPAY_PLATFORM_KEY_DIR=/data/config

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        git \
        gosu \
        unzip \
        libcurl4-openssl-dev \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libxml2-dev \
        libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        curl \
        gd \
        mbstring \
        opcache \
        pcntl \
        pdo_mysql \
        posix \
        sockets \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app

ARG COMPOSER_REPO=

# Install PHP dependencies first so source-only changes can reuse this Docker layer.
COPY composer.json composer.lock ./
RUN if [ -n "$COMPOSER_REPO" ]; then composer config -g repos.packagist composer "$COMPOSER_REPO"; fi \
    && composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --optimize-autoloader \
    --no-scripts

COPY . /app
RUN composer dump-autoload --no-dev --optimize --no-scripts \
    && mkdir -p /app/runtime /app/public/storage /data/config \
    && chmod +x /app/docker/docker-entrypoint.sh \
    && chown -R www-data:www-data /app/runtime /app/public/storage /data/config

EXPOSE 8787

HEALTHCHECK --interval=15s --timeout=5s --start-period=20s --retries=5 \
    CMD curl -fsS http://127.0.0.1:8787/adminapi/install/status >/dev/null || exit 1

ENTRYPOINT ["/app/docker/docker-entrypoint.sh"]
CMD ["php", "start.php", "start"]
