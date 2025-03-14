<?php

namespace App\Tests\Entity;

use App\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

class UserTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        $this->user = new User();
    }

    public function testUserImplementsInterfaces(): void
    {
        $this->assertInstanceOf(PasswordAuthenticatedUserInterface::class, $this->user);
    }

    public function testUserCreation(): void
    {
        $this->assertNotNull($this->user->getCreatedAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $this->user->getCreatedAt());
    }

    public function testUserSettersAndGetters(): void
    {
        $this->user->setUsername('testuser');
        $this->user->setEmail('test@example.com');
        $this->user->setName('Test User');
        $this->user->setAddress('123 Test St');
        $this->user->setRoles(['ROLE_USER']);
        $this->user->setPassword('hashed_password');

        $this->assertEquals('testuser', $this->user->getUsername());
        $this->assertEquals('test@example.com', $this->user->getEmail());
        $this->assertEquals('Test User', $this->user->getName());
        $this->assertEquals('123 Test St', $this->user->getAddress());
        $this->assertEquals(['ROLE_USER'], $this->user->getRoles());
        $this->assertEquals('hashed_password', $this->user->getPassword());
    }

    public function testUserRoles(): void
    {
        $this->user->setRoles(['ROLE_ADMIN']);
        $roles = $this->user->getRoles();
        
        $this->assertContains('ROLE_ADMIN', $roles);
        $this->assertContains('ROLE_USER', $roles); // ROLE_USER добавляется автоматически
    }

    public function testUserIdentifier(): void
    {
        $this->user->setUsername('testuser');
        $this->assertEquals('testuser', $this->user->getUserIdentifier());
    }

    public function testTwitterIntegration(): void
    {
        $this->user->setTwitterId('123456');
        $this->user->setTwitterAccessToken('access_token');
        $this->user->setTwitterAccessTokenSecret('access_token_secret');

        $this->assertEquals('123456', $this->user->getTwitterId());
        $this->assertEquals('access_token', $this->user->getTwitterAccessToken());
        $this->assertEquals('access_token_secret', $this->user->getTwitterAccessTokenSecret());
    }

    public function testEraseCredentials(): void
    {
        // Метод должен быть пустым, так как мы не храним временные данные
        $this->user->eraseCredentials();
        $this->assertTrue(true); // Если метод выполнился без ошибок, тест пройден
    }
} 