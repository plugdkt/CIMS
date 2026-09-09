FROM php:8.3-fpm-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libzip-dev libpng-dev libonig-dev libicu-dev libxml2-dev \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath pdo_mysql mysqli gd zip intl exif pcntl opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY www.conf /usr/local/etc/php-fpm.d/www.conf
COPY opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini

WORKDIR /var/www/html

EXPOSE 9000
