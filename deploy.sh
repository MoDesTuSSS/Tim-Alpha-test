#!/bin/bash

# Остановка и удаление существующих контейнеров
docker-compose down

# Сборка и запуск контейнеров
docker-compose up -d --build

# Ожидание запуска базы данных
echo "Ожидание запуска базы данных..."
sleep 10

# Выполнение миграций
docker-compose exec php php bin/console doctrine:database:create --if-not-exists
docker-compose exec php php bin/console doctrine:migrations:migrate --no-interaction

# Создание очереди и обмена в RabbitMQ
echo "Настройка RabbitMQ..."
docker-compose exec rabbitmq rabbitmqadmin -u symfony -p symfony declare exchange name=messages type=direct
docker-compose exec rabbitmq rabbitmqadmin -u symfony -p symfony declare queue name=messages
docker-compose exec rabbitmq rabbitmqadmin -u symfony -p symfony declare binding source=messages destination=messages routing_key=messages

echo "Развертывание завершено!"
echo "Приложение доступно по адресу: http://localhost:8080"
echo "RabbitMQ Management доступен по адресу: http://localhost:15672"
echo "MailHog доступен по адресу: http://localhost:8025" 