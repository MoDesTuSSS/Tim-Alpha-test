<?php

namespace App\Tests\Controller;

use App\Controller\UserController;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Email;

class UserControllerTest extends TestCase
{
    private EntityManagerInterface $entityManager;
    private MailerInterface $mailer;
    private UserController $controller;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->mailer = $this->createMock(MailerInterface::class);
        $this->controller = new UserController($this->entityManager, $this->mailer);
        
        // Create temporary directory for test files
        $this->tempDir = sys_get_temp_dir() . '/user_test_' . uniqid();
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        // Cleanup temporary directory
        array_map('unlink', glob("$this->tempDir/*.*"));
        rmdir($this->tempDir);
    }

    public function testUploadUsersSuccess(): void
    {
        // Create test CSV file
        $csvContent = "name,email,username,address,role\n" .
                     "John Doe,john@example.com,johndoe,\"123 Main St\",ROLE_USER\n" .
                     "Jane Smith,jane@example.com,janesmith,\"456 Park Ave\",ROLE_ADMIN";
        $csvPath = $this->tempDir . '/test.csv';
        file_put_contents($csvPath, $csvContent);

        // Create uploaded file
        $file = new UploadedFile(
            $csvPath,
            'test.csv',
            'text/csv',
            null,
            true
        );

        // Configure entity manager expectations
        $this->entityManager->expects($this->exactly(2))
            ->method('persist')
            ->willReturnCallback(function ($user) {
                $this->assertInstanceOf(User::class, $user);
                $this->assertTrue(
                    in_array($user->getName(), ['John Doe', 'Jane Smith']) &&
                    in_array($user->getEmail(), ['john@example.com', 'jane@example.com']) &&
                    in_array($user->getUsername(), ['johndoe', 'janesmith']) &&
                    in_array($user->getAddress(), ['123 Main St', '456 Park Ave'])
                );
                $roles = $user->getRoles();
                $this->assertTrue(
                    in_array('ROLE_USER', $roles) || in_array('ROLE_ADMIN', $roles)
                );
            });

        $this->entityManager->expects($this->once())
            ->method('flush');

        // Configure mailer expectations
        $this->mailer->expects($this->exactly(2))
            ->method('send')
            ->willReturnCallback(function ($email) {
                $this->assertInstanceOf(Email::class, $email);
                $this->assertTrue(in_array($email->getTo()[0]->getAddress(), ['john@example.com', 'jane@example.com']));
                return;
            });

        // Create request with file
        $request = Request::create('/api/upload', 'POST');
        $request->files->set('file', $file);

        // Execute controller
        $response = $this->controller->uploadUsers($request);

        // Log error content if status code is not 201
        if ($response->getStatusCode() !== Response::HTTP_CREATED) {
            $content = json_decode($response->getContent(), true);
            var_dump($content);
        }

        // Assert response
        $this->assertEquals(Response::HTTP_CREATED, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertEquals('Users imported successfully', $content['message']);
        $this->assertEquals(2, $content['count']);
    }

    public function testUploadUsersWithInvalidFile(): void
    {
        // Create request without file
        $request = Request::create('/api/upload', 'POST');
        
        // Execute controller
        $response = $this->controller->uploadUsers($request);

        // Assert response
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertEquals('No file uploaded', $content['error']);
    }

    public function testGetUsers(): void
    {
        // Create test users
        $user1 = new User();
        $user1->setName('John Doe');
        $user1->setEmail('john@example.com');
        $user1->setUsername('johndoe');
        $user1->setAddress('123 Main St');
        $user1->setRoles(['ROLE_USER']);

        $user2 = new User();
        $user2->setName('Jane Smith');
        $user2->setEmail('jane@example.com');
        $user2->setUsername('janesmith');
        $user2->setAddress('456 Park Ave');
        $user2->setRoles(['ROLE_ADMIN']);

        // Configure repository mock
        $repository = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $repository->expects($this->once())
            ->method('findAll')
            ->willReturn([$user1, $user2]);

        $this->entityManager->expects($this->once())
            ->method('getRepository')
            ->with(User::class)
            ->willReturn($repository);

        // Execute controller
        $response = $this->controller->getUsers();

        // Assert response
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertCount(2, $content);
        $this->assertEquals('John Doe', $content[0]['name']);
        $this->assertEquals('jane@example.com', $content[1]['email']);
    }

    public function testUploadUsersWithInvalidCSVStructure(): void
    {
        // Create test CSV file with invalid structure
        $csvContent = "name,email\n" . // Missing required columns
                     "John Doe,john@example.com";
        $csvPath = $this->tempDir . '/invalid.csv';
        file_put_contents($csvPath, $csvContent);

        // Create uploaded file
        $file = new UploadedFile(
            $csvPath,
            'invalid.csv',
            'text/csv',
            null,
            true
        );

        // Create request with file
        $request = Request::create('/api/upload', 'POST');
        $request->files->set('file', $file);

        // Execute controller
        $response = $this->controller->uploadUsers($request);

        // Assert response
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertStringContainsString('Invalid CSV structure', $content['error']);
    }

    public function testUploadUsersWithInvalidEmail(): void
    {
        // Create test CSV file with invalid email
        $csvContent = "name,email,username,address,role\n" .
                     "John Doe,invalid-email,johndoe,\"123 Main St\",ROLE_USER";
        $csvPath = $this->tempDir . '/invalid_email.csv';
        file_put_contents($csvPath, $csvContent);

        // Create uploaded file
        $file = new UploadedFile(
            $csvPath,
            'invalid_email.csv',
            'text/csv',
            null,
            true
        );

        // Create request with file
        $request = Request::create('/api/upload', 'POST');
        $request->files->set('file', $file);

        // Execute controller
        $response = $this->controller->uploadUsers($request);

        // Assert response
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertStringContainsString('Invalid email format', $content['error']);
    }
} 