# Base stage for both dev and prod
FROM php:8.2-fpm as app_php

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    curl \
    && docker-php-ext-install zip pdo pdo_mysql opcache \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug opcache

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www

# Development stage
FROM app_php as app_php_dev

# Copy the entire application
COPY . .

# Install dependencies
RUN APP_ENV=dev composer install --prefer-dist

# Setup PHPUnit
RUN curl -Lo /var/www/bin/phpunit https://phar.phpunit.de/phpunit-9.5.phar && \
    chmod +x /var/www/bin/phpunit

# Production stage
FROM app_php as app_php_prod

# Copy application files
COPY . .

# Install production dependencies
RUN APP_ENV=prod composer install --prefer-dist --no-dev --optimize-autoloader

# Set permissions
RUN chown -R www-data:www-data /var/www

# Switch to www-data user
USER www-data 