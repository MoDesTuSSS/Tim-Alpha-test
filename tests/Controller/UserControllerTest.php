<?php

namespace App\Tests\Controller;

use App\Controller\UserController;
use App\Entity\User;
use App\Message\Async\ProcessUserMessage;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Messenger\Envelope;

class UserControllerTest extends TestCase
{
    private EntityManagerInterface $entityManager;
    private SerializerInterface $serializer;
    private MessageBusInterface $messageBus;
    private UserController $controller;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->serializer = $this->createMock(SerializerInterface::class);
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->controller = new UserController(
            $this->entityManager,
            $this->serializer,
            $this->messageBus
        );
    }

    public function testList(): void
    {
        // Создаем тестовых пользователей
        $users = [
            $this->createTestUser('John Doe', 'john@example.com'),
            $this->createTestUser('Jane Smith', 'jane@example.com')
        ];

        // Настраиваем моки
        $repository = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $repository->expects($this->once())
            ->method('findAll')
            ->willReturn($users);

        $this->entityManager->expects($this->once())
            ->method('getRepository')
            ->with(User::class)
            ->willReturn($repository);

        $this->serializer->expects($this->once())
            ->method('serialize')
            ->with($users, 'json', ['groups' => 'user:read'])
            ->willReturn(json_encode([
                ['name' => 'John Doe', 'email' => 'john@example.com'],
                ['name' => 'Jane Smith', 'email' => 'jane@example.com']
            ]));

        // Выполняем тест
        $response = $this->controller->list();

        // Проверяем результат
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertCount(2, $content);
        $this->assertEquals('John Doe', $content[0]['name']);
        $this->assertEquals('jane@example.com', $content[1]['email']);
    }

    public function testCreate(): void
    {
        // Создаем тестовые данные
        $userData = [
            'username' => 'johndoe',
            'email' => 'john@example.com',
            'name' => 'John Doe'
        ];

        // Создаем запрос
        $request = new Request([], [], [], [], [], [], json_encode($userData));

        // Настраиваем моки
        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function ($user) use ($userData) {
                $this->assertInstanceOf(User::class, $user);
                $this->assertEquals($userData['username'], $user->getUsername());
                $this->assertEquals($userData['email'], $user->getEmail());
                $this->assertEquals($userData['name'], $user->getName());
                
                // Устанавливаем ID после проверки
                $reflectionClass = new \ReflectionClass(User::class);
                $property = $reflectionClass->getProperty('id');
                $property->setAccessible(true);
                $property->setValue($user, 1);
                
                return true;
            }));

        $this->entityManager->expects($this->once())
            ->method('flush');

        $envelope = new Envelope(new ProcessUserMessage(1));
        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(ProcessUserMessage::class))
            ->willReturn($envelope);

        $this->serializer->expects($this->once())
            ->method('serialize')
            ->willReturn(json_encode($userData));

        // Выполняем тест
        $response = $this->controller->create($request);

        // Проверяем результат
        $this->assertEquals(Response::HTTP_CREATED, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertEquals($userData['username'], $content['username']);
        $this->assertEquals($userData['email'], $content['email']);
    }

    public function testShow(): void
    {
        // Создаем тестового пользователя
        $user = $this->createTestUser('John Doe', 'john@example.com');

        // Настраиваем моки
        $this->serializer->expects($this->once())
            ->method('serialize')
            ->with($user, 'json', ['groups' => 'user:read'])
            ->willReturn(json_encode([
                'name' => 'John Doe',
                'email' => 'john@example.com'
            ]));

        // Выполняем тест
        $response = $this->controller->show($user);

        // Проверяем результат
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertEquals('John Doe', $content['name']);
        $this->assertEquals('john@example.com', $content['email']);
    }

    private function createTestUser(string $name, string $email): User
    {
        $user = new User();
        $user->setName($name);
        $user->setEmail($email);
        $user->setUsername(strtolower(str_replace(' ', '', $name)));
        return $user;
    }
} 