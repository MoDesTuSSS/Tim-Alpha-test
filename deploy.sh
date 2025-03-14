#!/bin/bash

# Stop and remove existing containers
docker-compose down

# Build and start containers
docker-compose up -d --build

# Wait for services to start
echo "Waiting for services to start..."
sleep 10

# Run database migrations
docker-compose exec php php bin/console doctrine:migrations:migrate

# Clear cache
docker-compose exec php php bin/console cache:clear

echo "Deployment completed successfully!"

# Создание очереди и обмена в RabbitMQ
echo "Настройка RabbitMQ..."
docker-compose exec -T rabbitmq rabbitmqctl wait /var/lib/rabbitmq/mnesia/rabbit@rabbitmq.pid
docker-compose exec -T rabbitmq rabbitmqadmin -u admin -p admin declare exchange name=messages type=direct
docker-compose exec -T rabbitmq rabbitmqadmin -u admin -p admin declare queue name=messages
docker-compose exec -T rabbitmq rabbitmqadmin -u admin -p admin declare binding source=messages destination=messages routing_key=messages

echo "Развертывание завершено!"
echo "Приложение доступно по адресу: http://localhost:8080"
echo "RabbitMQ Management доступен по адресу: http://localhost:15672"
echo "MailHog доступен по адресу: http://localhost:8025" 