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
    private string $projectDir;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->serializer = $this->createMock(SerializerInterface::class);
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->projectDir = sys_get_temp_dir();
        
        $this->controller = new UserController(
            $this->entityManager,
            $this->serializer,
            $this->messageBus,
            $this->projectDir
        );
    }

    public function testList(): void
    {
        // Create test request
        $request = new Request();

        // Configure mocks
        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(\App\Message\Async\GenerateUsersListMessage::class))
            ->willReturn(new Envelope(new \App\Message\Async\GenerateUsersListMessage('test-id', null, [])));

        // Execute test
        $response = $this->controller->list($request);

        // Verify result
        $this->assertEquals(Response::HTTP_ACCEPTED, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('export_id', $content);
        $this->assertArrayHasKey('status_url', $content);
        $this->assertArrayHasKey('download_url', $content);
    }

    public function testCreate(): void
    {
        // Create test data
        $userData = [
            'username' => 'johndoe',
            'email' => 'john@example.com',
            'name' => 'John Doe'
        ];

        // Create request
        $request = new Request([], [], [], [], [], [], json_encode($userData));

        // Configure mocks
        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function ($user) use ($userData) {
                $this->assertInstanceOf(User::class, $user);
                $this->assertEquals($userData['username'], $user->getUsername());
                $this->assertEquals($userData['email'], $user->getEmail());
                $this->assertEquals($userData['name'], $user->getName());
                
                // Set ID after verification
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

        // Execute test
        $response = $this->controller->create($request);

        // Verify result
        $this->assertEquals(Response::HTTP_CREATED, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertEquals($userData['username'], $content['username']);
        $this->assertEquals($userData['email'], $content['email']);
    }

    public function testShow(): void
    {
        // Create test user
        $user = $this->createTestUser('John Doe', 'john@example.com');

        // Configure mocks
        $this->serializer->expects($this->once())
            ->method('serialize')
            ->with($user, 'json', ['groups' => 'user:read'])
            ->willReturn(json_encode([
                'name' => 'John Doe',
                'email' => 'john@example.com'
            ]));

        // Execute test
        $response = $this->controller->show($user);

        // Verify result
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