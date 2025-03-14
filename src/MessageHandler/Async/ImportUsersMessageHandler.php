<?php

namespace App\MessageHandler\Async;

use App\Message\Async\ImportUsersMessage;
use App\Message\Async\ProcessUserMessage;
use App\Service\UserValidator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Ramsey\Uuid\Uuid;

#[AsMessageHandler(bus: 'messenger.bus.default')]
class ImportUsersMessageHandler
{
    private const BATCH_SIZE = 100;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $messageBus,
        private string $projectDir,
        private LoggerInterface $logger,
        private UserValidator $userValidator
    ) {}

    public function __invoke(ImportUsersMessage $message): void
    {
        $importId = Uuid::uuid4()->toString();
        $statusFile = $this->projectDir . '/var/import_status/' . $importId . '.json';
        $validationLogFile = $this->projectDir . '/var/log/import_validation_' . $importId . '.log';
        $statusDir = dirname($statusFile);

        if (!is_dir($statusDir)) {
            mkdir($statusDir, 0777, true);
        }

        $this->updateStatus($statusFile, [
            'status' => 'processing',
            'message' => 'Starting import process',
            'total_processed' => 0,
            'total_validated' => 0,
            'total_skipped' => 0,
            'errors' => []
        ]);

        $this->logger->info('Starting async user import process', ['filename' => $message->getFilename()]);
        
        $filepath = $this->projectDir . '/import/' . $message->getFilename();
        
        if (!file_exists($filepath)) {
            $error = 'File not found';
            $this->logger->error($error, ['filepath' => $filepath]);
            $this->updateStatus($statusFile, [
                'status' => 'failed',
                'message' => $error,
                'total_processed' => 0,
                'total_validated' => 0,
                'total_skipped' => 0,
                'errors' => [$error]
            ]);
            return;
        }

        $file = fopen($filepath, 'r');
        if (!$file) {
            $error = 'Could not open file';
            $this->logger->error($error, ['filepath' => $filepath]);
            $this->updateStatus($statusFile, [
                'status' => 'failed',
                'message' => $error,
                'total_processed' => 0,
                'total_validated' => 0,
                'total_skipped' => 0,
                'errors' => [$error]
            ]);
            return;
        }

        // Пропускаем заголовок
        $headers = fgetcsv($file);
        if (!$headers) {
            $error = 'Invalid CSV format: no headers found';
            $this->logger->error($error);
            $this->updateStatus($statusFile, [
                'status' => 'failed',
                'message' => $error,
                'total_processed' => 0,
                'total_validated' => 0,
                'total_skipped' => 0,
                'errors' => [$error]
            ]);
            fclose($file);
            return;
        }

        $this->logger->info('CSV headers found', ['headers' => $headers]);

        $users = [];
        $row = 2; // Начинаем со второй строки (после заголовка)
        $totalProcessed = 0;
        $totalValidated = 0;
        $totalSkipped = 0;
        $errors = [];
        
        try {
            while (($data = fgetcsv($file)) !== false) {
                $this->logger->debug('Processing row', ['row_number' => $row, 'data' => $data]);
                
                if (count($data) !== count($headers)) {
                    $error = 'Invalid CSV format: column count mismatch';
                    $this->logger->error($error, [
                        'row' => $row,
                        'expected' => count($headers),
                        'got' => count($data)
                    ]);
                    $errors[] = $error;
                    $totalSkipped++;
                    continue;
                }

                $userData = array_combine($headers, $data);
                $this->logger->debug('User data parsed', ['userData' => $userData]);
                
                try {
                    // Валидация данных пользователя
                    $validationErrors = $this->userValidator->validateUserData($userData, $row);
                    if (!empty($validationErrors)) {
                        $this->logValidationError($validationLogFile, $row, $userData, $validationErrors);
                        $totalSkipped++;
                        continue;
                    }

                    // Проверяем, существует ли пользователь с таким email
                    $existingUser = $this->entityManager->getRepository(\App\Entity\User::class)->findOneBy(['email' => $userData['email']]);
                    if ($existingUser) {
                        $this->logger->info('User already exists', ['email' => $userData['email']]);
                        $totalSkipped++;
                        continue;
                    }

                    $user = new \App\Entity\User();
                    $user->setUsername($userData['username']);
                    $user->setEmail($userData['email']);
                    $user->setName($userData['name']);
                    $user->setAddress($userData['address'] ?? null);
                    $user->setRoles(['ROLE_' . $userData['role']]);
                    $user->setPassword('temporary_password_' . uniqid());
                    
                    $this->entityManager->persist($user);
                    $users[] = $user;
                    $totalValidated++;
                    
                    // Каждые 100 пользователей сохраняем в базу и отправляем на обработку
                    if (count($users) % self::BATCH_SIZE === 0) {
                        $this->logger->info('Flushing batch of users', ['count' => count($users)]);
                        $this->entityManager->flush();
                        
                        // Отправляем сообщения для асинхронной обработки
                        foreach ($users as $user) {
                            $this->messageBus->dispatch(new ProcessUserMessage($user->getId()));
                        }
                        
                        $users = [];
                    }
                } catch (\Exception $e) {
                    $error = 'Error creating user: ' . $e->getMessage();
                    $this->logger->error($error, [
                        'row' => $row,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    $errors[] = $error;
                    $totalSkipped++;
                }
                
                $row++;
                $totalProcessed++;
                
                // Обновляем статус каждые 100 записей
                if ($totalProcessed % 100 === 0) {
                    $this->updateStatus($statusFile, [
                        'status' => 'processing',
                        'message' => 'Processing records',
                        'total_processed' => $totalProcessed,
                        'total_validated' => $totalValidated,
                        'total_skipped' => $totalSkipped,
                        'errors' => $errors
                    ]);
                }
                
                // Очищаем память каждые 1000 строк
                if ($totalProcessed % 1000 === 0) {
                    gc_collect_cycles();
                }
            }

            // Сохраняем оставшихся пользователей
            if (count($users) > 0) {
                $this->logger->info('Flushing remaining users', ['count' => count($users)]);
                $this->entityManager->flush();
                
                // Отправляем сообщения для асинхронной обработки
                foreach ($users as $user) {
                    $this->messageBus->dispatch(new ProcessUserMessage($user->getId()));
                }
            }
        } catch (\Exception $e) {
            $error = 'Error during import process: ' . $e->getMessage();
            $this->logger->error($error, [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $errors[] = $error;
        } finally {
            fclose($file);
        }

        $this->logger->info('Import completed successfully', [
            'total_processed' => $totalProcessed,
            'total_validated' => $totalValidated,
            'total_skipped' => $totalSkipped
        ]);
        
        $this->updateStatus($statusFile, [
            'status' => count($errors) > 0 ? 'completed_with_errors' : 'completed',
            'message' => 'Import completed',
            'total_processed' => $totalProcessed,
            'total_validated' => $totalValidated,
            'total_skipped' => $totalSkipped,
            'errors' => $errors
        ]);
    }

    private function updateStatus(string $statusFile, array $status): void
    {
        file_put_contents($statusFile, json_encode($status, JSON_PRETTY_PRINT));
    }

    private function logValidationError(string $logFile, int $row, array $userData, array $errors): void
    {
        $logEntry = sprintf(
            "[%s] Row %d: Validation failed\nData: %s\nErrors: %s\n\n",
            date('Y-m-d H:i:s'),
            $row,
            json_encode($userData, JSON_PRETTY_PRINT),
            implode("\n", $errors)
        );

        file_put_contents($logFile, $logEntry, FILE_APPEND);
    }
} 