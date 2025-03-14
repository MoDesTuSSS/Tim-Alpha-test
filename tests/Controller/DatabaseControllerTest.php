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

class DatabaseControllerTest extends TestCase
{
    private KernelInterface $kernel;
    private DatabaseController $controller;
    private string $backupDir;

    protected function setUp(): void
    {
        $this->kernel = $this->createMock(KernelInterface::class);
        $this->controller = new DatabaseController($this->kernel);
        
        // Создаем временную директорию для тестовых файлов
        $this->backupDir = sys_get_temp_dir() . '/backup_test_' . uniqid();
        mkdir($this->backupDir, 0777, true);
        
        $this->kernel->expects($this->any())
            ->method('getProjectDir')
            ->willReturn($this->backupDir);
    }

    protected function tearDown(): void
    {
        // Очищаем временную директорию
        if (is_dir($this->backupDir)) {
            $backupPath = $this->backupDir . '/var/backup';
            if (is_dir($backupPath)) {
                array_map('unlink', glob($backupPath . '/*.*'));
                rmdir($backupPath);
            }
            if (is_dir($this->backupDir . '/var')) {
                rmdir($this->backupDir . '/var');
            }
            rmdir($this->backupDir);
        }
    }

    public function testBackupSuccess(): void
    {
        // Создаем тестовый файл резервной копии
        $backupFile = $this->backupDir . '/var/backup/backup_20240320_123456.sql';
        mkdir(dirname($backupFile), 0777, true);
        file_put_contents($backupFile, 'CREATE TABLE test (id INT);');

        // Настраиваем мок приложения
        $application = $this->createMock(Application::class);
        $application->expects($this->once())
            ->method('run')
            ->willReturn(0);

        // Настраиваем мок ядра
        $this->kernel->expects($this->any())
            ->method('getProjectDir')
            ->willReturn($this->backupDir);

        // Внедряем мок приложения
        $this->controller->setApplication($application);

        // Выполняем тест
        $response = $this->controller->backup();

        // Проверяем, что это BinaryFileResponse
        $this->assertInstanceOf('Symfony\Component\HttpFoundation\BinaryFileResponse', $response);
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        
        // Проверяем имя файла в заголовке Content-Disposition
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('backup_20240320_123456.sql', $disposition);
        $this->assertStringContainsString('attachment', $disposition);
    }

    public function testBackupFailure(): void
    {
        // Настраиваем мок приложения для возврата ошибки
        $application = $this->createMock(Application::class);
        $application->expects($this->once())
            ->method('run')
            ->willReturn(1);

        // Настраиваем мок ядра
        $this->kernel->expects($this->any())
            ->method('getProjectDir')
            ->willReturn($this->backupDir);

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