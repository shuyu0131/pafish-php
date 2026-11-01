FROM php:8.3-cli-bookworm AS vendor

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends libicu-dev libzip-dev; \
    docker-php-ext-install -j"$(nproc)" intl zip; \
    rm -rf /var/lib/apt/lists/*

COPY --from=composer:2.8 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --prefer-dist \
        --no-interaction \
        --no-progress \
        --optimize-autoloader

FROM php:8.3-apache-bookworm

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libcurl4-openssl-dev \
        libxml2-dev \
        libonig-dev \
        libpng-dev \
        libzip-dev; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" curl dom gd intl mbstring pdo_mysql zip; \
    a2enmod rewrite headers; \
    sed -ri 's/^Listen 80$/Listen 8080/' /etc/apache2/ports.conf; \
    sed -ri 's#<VirtualHost \*:80>#<VirtualHost *:8080>#' /etc/apache2/sites-available/000-default.conf; \
    rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY . /var/www/html
COPY --from=vendor /app/vendor /var/www/html/vendor
COPY docker/apache-pafish.conf /etc/apache2/conf-enabled/pafish.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-pafish.ini
COPY docker/entrypoint.sh /usr/local/bin/pafish-entrypoint
COPY themes/default /usr/local/share/pafish-seed/themes/default
COPY plugins/sitemap /usr/local/share/pafish-seed/plugins/sitemap
COPY plugins/ai_foundation /usr/local/share/pafish-seed/plugins/ai_foundation

RUN set -eux; \
    mkdir -p runtime public/uploads backups storage plugins themes; \
    chown -R www-data:www-data runtime public/uploads backups storage plugins themes; \
    chown -R www-data:www-data /usr/local/share/pafish-seed; \
    chmod 0755 /usr/local/bin/pafish-entrypoint

ENV APACHE_RUN_USER=www-data \
    APACHE_RUN_GROUP=www-data

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=3s --start-period=20s --retries=3 \
    CMD ["php", "-r", "$s=@fsockopen('127.0.0.1',8080);if(!$s){exit(1);}fclose($s);"]

ENTRYPOINT ["pafish-entrypoint"]
CMD ["apache2-foreground"]
