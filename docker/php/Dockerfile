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
    netcat-openbsd

# Очистка кэша apt
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# Установка PHP расширений
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd sockets
RUN pecl install amqp && docker-php-ext-enable amqp

# Установка Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Установка рабочей директории
WORKDIR /var/www

# Копирование файлов проекта
COPY . .

# Установка зависимостей через Composer
RUN composer install --no-interaction --optimize-autoloader --ignore-platform-reqs

# Установка прав на директории
RUN chown -R www-data:www-data /var/www

# Открытие порта
EXPOSE 9000

# Запуск PHP-FPM
CMD ["php-fpm"] 