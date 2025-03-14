FROM php:8.2-fpm

# Установка системных зависимостей
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    librabbitmq-dev \
    netcat-openbsd \
    && rm -rf /var/lib/apt/lists/*

# Установка PHP расширений
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd sockets opcache
RUN pecl install amqp && docker-php-ext-enable amqp opcache

# Оптимизация PHP для production
COPY docker/php/conf.d/opcache.ini /usr/local/etc/php/conf.d/
COPY docker/php/conf.d/amqp.ini /usr/local/etc/php/conf.d/

# Установка Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Настройка Composer
ENV COMPOSER_ALLOW_SUPERUSER=1
ENV COMPOSER_MEMORY_LIMIT=-1

# Установка рабочей директории
WORKDIR /var/www

# Копирование файлов проекта
COPY . .

# Установка зависимостей через Composer
RUN composer install --prefer-dist --no-scripts --no-autoloader --no-interaction --ignore-platform-reqs

# Генерация автозагрузчика и установка прав
RUN composer dump-autoload --optimize \
    && chown -R www-data:www-data /var/www \
    && chmod -R 755 /var/www/var

# Создание директорий для логов и кэша с правильными правами
RUN mkdir -p /var/www/var/cache /var/www/var/log \
    && chown -R www-data:www-data /var/www/var \
    && chmod -R 777 /var/www/var

# Очистка
RUN composer clear-cache \
    && rm -rf /tmp/*

# Открытие порта
EXPOSE 9000

# Запуск PHP-FPM
CMD ["php-fpm"] 