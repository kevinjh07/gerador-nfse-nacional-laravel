FROM php:8.1-cli

RUN apt-get update && apt-get install -y \
    libxml2-dev \
    libssl-dev \
    unzip \
    git

RUN docker-php-ext-install pdo pdo_mysql soap bcmath

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
