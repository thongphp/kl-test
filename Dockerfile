FROM php:8.5-fpm-alpine

# allow composer to run as root in CI/container
ENV COMPOSER_ALLOW_SUPERUSER=1 \
    PATH=/root/.composer/vendor/bin:$PATH

WORKDIR /var/www/html

# Install runtime deps and build deps, then install extensions
RUN set -eux; \
    apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        autoconf \
        g++ \
        make \
        libtool \
        curl \
        bash \
        zlib-dev \
        libzip-dev \
        oniguruma-dev \
        postgresql-dev; \
    \
    # runtime packages (must NOT be in .build-deps, or `apk del` below removes them)
    # - libzip: shared library needed by the zip extension at runtime
    # - git, unzip: needed by composer to install packages
    apk add --no-cache \
        postgresql-client \
        icu-libs \
        libzip \
        git \
        unzip; \
    \
    # Install and enable php extensions built-in
    docker-php-ext-install -j "$(nproc)" pdo_pgsql pgsql zip intl mbstring; \
    \
    # Install PECL extensions: redis and pcov
    pecl channel-update pecl.php.net; \
    pecl install redis pcov || (pecl install redis && pecl install pcov); \
    docker-php-ext-enable redis pcov; \
    \
    # Configure pcov (enabled by default here; change as needed)
    printf "%s\n" "pcov.enabled=1" "pcov.directory=/var/www/html" > /usr/local/etc/php/conf.d/pcov.ini; \
    \
    # Cleanup build dependencies
    apk del .build-deps; \
    rm -rf /var/cache/apk/* /tmp/pear

# Install Composer (official installer)
RUN set -eux; \
    curl -sS https://getcomposer.org/installer -o /tmp/composer-setup.php; \
    php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer; \
    rm -f /tmp/composer-setup.php

# Set permissions for Laravel (adjust user/group if needed)
RUN chown -R www-data:www-data /var/www/html

# Copy only composer files first to leverage Docker cache for composer install
COPY composer.json composer.lock* ./

# Note: docker-compose bind-mounts ./ over /var/www/html, so this vendor/ is hidden
# at runtime. After `docker compose up -d`, run:
#   docker compose exec app composer install
RUN composer install --prefer-dist --no-interaction --no-progress --no-scripts

# Copy application code (if building image with app code included)
COPY . .

# Ensure storage & cache directories are writable
RUN mkdir -p storage/framework storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Expose php-fpm socket/port
EXPOSE 9000

# Use the php-fpm entrypoint from the base image
CMD ["php-fpm"]
