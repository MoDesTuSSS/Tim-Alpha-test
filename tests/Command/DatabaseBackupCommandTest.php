<?php

namespace App\Tests\Command;

use App\Command\DatabaseBackupCommand;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Console\Command\Command;

class DatabaseBackupCommandTest extends TestCase
{
    private Connection $connection;
    private ParameterBagInterface $params;
    private CommandTester $commandTester;
    private string $backupDir;
    private string $varBackupDir;

    protected function setUp(): void
    {
        // Create mock objects
        $this->connection = $this->createMock(Connection::class);
        $this->params = $this->createMock(ParameterBagInterface::class);
        
        // Setup temporary backup directory
        $this->backupDir = sys_get_temp_dir() . '/backup_test_' . uniqid();
        $this->varBackupDir = $this->backupDir . '/var/backup';
        mkdir($this->varBackupDir, 0777, true);
        
        $this->params->expects($this->any())
            ->method('get')
            ->with('kernel.project_dir')
            ->willReturn($this->backupDir);

        // Create command
        $command = new DatabaseBackupCommand($this->connection, $this->params);
        $application = new Application();
        $application->add($command);

        $this->commandTester = new CommandTester($command);
    }

    protected function tearDown(): void
    {
        // Cleanup temporary directory
        if (is_dir($this->varBackupDir)) {
            array_map('unlink', glob($this->varBackupDir . '/*.*'));
            rmdir($this->varBackupDir);
            rmdir(dirname($this->varBackupDir));
            rmdir($this->backupDir);
        }
    }

    public function testExecute(): void
    {
        // Setup schema manager mock
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $platform = $this->createMock(AbstractPlatform::class);
        $schema = $this->createMock(Schema::class);
        $table = $this->createMock(Table::class);

        // Configure mocks
        $this->connection->expects($this->once())
            ->method('createSchemaManager')
            ->willReturn($schemaManager);

        $this->connection->expects($this->once())
            ->method('getDatabasePlatform')
            ->willReturn($platform);

        $schemaManager->expects($this->once())
            ->method('introspectSchema')
            ->willReturn($schema);

        $schema->expects($this->exactly(2))
            ->method('getTables')
            ->willReturn([$table]);

        $table->expects($this->atLeastOnce())
            ->method('getName')
            ->willReturn('users');

        $platform->expects($this->once())
            ->method('getCreateTablesSQL')
            ->with($this->equalTo([$table]))
            ->willReturn(['CREATE TABLE users (id INT PRIMARY KEY)']);

        $this->connection->expects($this->once())
            ->method('fetchAllAssociative')
            ->with('SELECT * FROM users')
            ->willReturn([
                ['id' => 1, 'name' => 'John Doe']
            ]);

        $this->connection->expects($this->any())
            ->method('quote')
            ->willReturnCallback(function($value) {
                return "'$value'";
            });

        // Execute command
        $exitCode = $this->commandTester->execute([]);

        // Assert command was successful
        $this->assertEquals(Command::SUCCESS, $exitCode);
        
        // Assert output contains success message
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Database backup created:', $output);

        // Assert backup file was created
        $files = glob($this->varBackupDir . '/*.sql');
        $this->assertCount(1, $files);

        // Assert backup file content
        $backupContent = file_get_contents($files[0]);
        $this->assertStringContainsString('CREATE TABLE users', $backupContent);
        $this->assertStringContainsString('INSERT INTO users', $backupContent);
    }

    public function testExecuteWithError(): void
    {
        // Setup schema manager mock that throws exception
        $schemaManager = $this->createMock(AbstractSchemaManager::class);

        // Configure mocks
        $this->connection->expects($this->once())
            ->method('createSchemaManager')
            ->willReturn($schemaManager);

        $schemaManager->expects($this->once())
            ->method('introspectSchema')
            ->willThrowException(new \Exception('Database error'));

        // Execute command
        $exitCode = $this->commandTester->execute([]);

        // Assert command failed
        $this->assertEquals(Command::FAILURE, $exitCode);
        
        // Assert output contains error message
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('[ERROR] Database error', $output);

        // Assert no backup file was created
        $files = glob($this->varBackupDir . '/*.sql');
        $this->assertCount(0, $files);
    }
} 