<?php

namespace App\Tests\Controller;

use App\Controller\DatabaseController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\JsonResponse;

class DatabaseControllerTest extends TestCase
{
    private KernelInterface $kernel;
    private DatabaseController $controller;
    private string $tempDir;

    protected function setUp(): void
    {
        // Create temporary directory for test files
        $this->tempDir = sys_get_temp_dir() . '/backup_test_' . uniqid();
        mkdir($this->tempDir);
        
        $this->kernel = $this->createMock(KernelInterface::class);
        $this->controller = new DatabaseController($this->kernel);
        
        $this->kernel->expects($this->any())
            ->method('getProjectDir')
            ->willReturn($this->tempDir);
    }

    protected function tearDown(): void
    {
        // Clean up temporary directory
        if (is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }
        
        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    public function testBackup(): void
    {
        // Create test backup file
        $backupContent = "-- Test backup content";
        $backupDir = $this->tempDir . '/var/backup';
        mkdir($backupDir, 0777, true);
        $backupFile = $backupDir . '/backup_' . date('Ymd_His') . '.sql';
        file_put_contents($backupFile, $backupContent);

        // Configure application mock
        $application = $this->createMock(Application::class);
        $application->expects($this->once())
            ->method('run')
            ->willReturn(0);

        // Inject application mock
        $this->controller->setApplication($application);

        // Execute test
        $response = $this->controller->backup();

        // Verify response
        $this->assertInstanceOf('Symfony\Component\HttpFoundation\BinaryFileResponse', $response);
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        
        // Check filename in Content-Disposition header
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('backup_', $disposition);
        $this->assertStringContainsString('.sql', $disposition);
        $this->assertStringContainsString('attachment', $disposition);
    }

    public function testBackupFailure(): void
    {
        // Настраиваем мок приложения для возврата ошибки
        $application = $this->createMock(Application::class);
        $application->expects($this->once())
            ->method('run')
            ->willReturn(1);

        // Внедряем мок приложения
        $this->controller->setApplication($application);

        // Выполняем тест
        $response = $this->controller->backup();

        // Проверяем результат
        $this->assertEquals(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('error', $content);
        $this->assertStringContainsString('Failed to create backup', $content['error']);
    }

    public function testRestoreSuccess(): void
    {
        // Создаем тестовый файл SQL
        $sqlFile = tempnam(sys_get_temp_dir(), 'test_');
        file_put_contents($sqlFile, 'CREATE TABLE test (id INT);');

        // Создаем UploadedFile
        $file = new UploadedFile(
            $sqlFile,
            'test.sql',
            'application/sql',
            null,
            true
        );

        // Создаем запрос
        $request = new Request();
        $request->files->set('file', $file);

        // Настраиваем мок приложения
        $application = $this->createMock(Application::class);
        $application->expects($this->once())
            ->method('run')
            ->willReturn(0);

        // Внедряем мок приложения
        $this->controller->setApplication($application);

        // Выполняем тест
        $response = $this->controller->restore($request);

        // Проверяем результат
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertEquals('Database restored successfully', $content['message']);

        // Очищаем
        unlink($sqlFile);
    }

    public function testRestoreWithoutFile(): void
    {
        // Создаем запрос без файла
        $request = new Request();

        // Выполняем тест
        $response = $this->controller->restore($request);

        // Проверяем результат
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertEquals('No file uploaded', $content['error']);
    }

    public function testRestoreWithInvalidFileType(): void
    {
        // Создаем тестовый файл неверного типа
        $invalidFile = tempnam(sys_get_temp_dir(), 'test_');
        file_put_contents($invalidFile, 'test content');

        // Создаем UploadedFile
        $file = new UploadedFile(
            $invalidFile,
            'test.txt',
            'text/plain',
            null,
            true
        );

        // Создаем запрос
        $request = new Request();
        $request->files->set('file', $file);

        // Выполняем тест
        $response = $this->controller->restore($request);

        // Проверяем результат
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertEquals('File must be a SQL dump', $content['error']);

        // Очищаем
        unlink($invalidFile);
    }

    public function testRestoreFailure(): void
    {
        // Создаем тестовый файл SQL
        $sqlFile = tempnam(sys_get_temp_dir(), 'test_');
        file_put_contents($sqlFile, 'CREATE TABLE test (id INT);');

        // Создаем UploadedFile
        $file = new UploadedFile(
            $sqlFile,
            'test.sql',
            'application/sql',
            null,
            true
        );

        // Создаем запрос
        $request = new Request();
        $request->files->set('file', $file);

        // Настраиваем мок приложения для возврата ошибки
        $application = $this->createMock(Application::class);
        $application->expects($this->once())
            ->method('run')
            ->willReturn(1);

        // Внедряем мок приложения
        $this->controller->setApplication($application);

        // Выполняем тест
        $response = $this->controller->restore($request);

        // Проверяем результат
        $this->assertEquals(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('error', $content);
        $this->assertStringContainsString('Failed to restore database', $content['error']);

        // Очищаем
        unlink($sqlFile);
    }
} 