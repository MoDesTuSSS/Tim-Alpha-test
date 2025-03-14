<?php

namespace App\Tests\Command;

use App\Command\ImportUsersCommand;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class ImportUsersCommandTest extends TestCase
{
    private CommandTester $commandTester;
    private EntityManagerInterface $entityManager;
    private UserPasswordHasherInterface $passwordHasher;
    private string $tempFile;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->passwordHasher = $this->createMock(UserPasswordHasherInterface::class);

        $command = new ImportUsersCommand($this->entityManager, $this->passwordHasher);
        $application = new Application();
        $application->add($command);

        $this->commandTester = new CommandTester($command);
        $this->tempFile = tempnam(sys_get_temp_dir(), 'test_');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }
    }

    public function testExecuteWithValidCsvFile(): void
    {
        // Создаем временный CSV файл
        $csvContent = "name,email,username,address,role\n";
        $csvContent .= "John Doe,john@example.com,johndoe,123 Main St,ROLE_USER\n";
        $csvContent .= "Jane Smith,jane@example.com,janesmith,456 Park Ave,ROLE_ADMIN";
        
        file_put_contents($this->tempFile, $csvContent);

        // Настраиваем моки
        $this->passwordHasher
            ->expects($this->exactly(2))
            ->method('hashPassword')
            ->willReturn('hashed_password');

        $this->entityManager
            ->expects($this->exactly(2))
            ->method('persist')
            ->with($this->isInstanceOf(User::class));

        $this->entityManager
            ->expects($this->once())
            ->method('flush');

        // Выполняем команду
        $this->commandTester->execute([
            'command' => 'app:database:import-users',
            'file' => $this->tempFile,
        ]);

        // Проверяем вывод
        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Successfully imported 2 users', $this->commandTester->getDisplay());
    }

    public function testExecuteWithNonExistentFile(): void
    {
        // Выполняем команду с несуществующим файлом
        $this->commandTester->execute([
            'command' => 'app:database:import-users',
            'file' => 'non_existent_file.csv',
        ]);

        // Проверяем вывод
        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('CSV file not found', $this->commandTester->getDisplay());
    }

    public function testExecuteWithInvalidCsvFormat(): void
    {
        // Создаем временный CSV файл с неверным форматом
        $csvContent = "invalid,format\n";
        $csvContent .= "John Doe,john@example.com\n";
        
        file_put_contents($this->tempFile, $csvContent);

        // Выполняем команду
        $this->commandTester->execute([
            'command' => 'app:database:import-users',
            'file' => $this->tempFile,
        ]);

        // Проверяем вывод
        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Undefined array key', $this->commandTester->getDisplay());
    }
} 