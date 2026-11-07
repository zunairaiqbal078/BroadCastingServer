FROM php:8.2-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends libzip-dev libpng-dev libonig-dev libxml2-dev libcurl4-openssl-dev \
 && docker-php-ext-install pdo_mysql mbstring zip gd curl \
 && a2enmod rewrite headers \
 && sed -i 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf \
 && rm -rf /var/lib/apt/lists/*

COPY php-dev.ini /usr/local/etc/php/conf.d/zz-castpanel-dev.ini
