<?php

namespace App\Controller;

use App\Entity\User;
use App\Message\Async\ProcessUserMessage;
use App\Message\Async\GenerateUsersListMessage;
use App\Message\Async\ImportUsersMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Serializer\SerializerInterface;
use Ramsey\Uuid\Uuid;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\TransportException;

#[Route('/api')]
class UserController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private SerializerInterface $serializer,
        private MessageBusInterface $messageBus,
        private string $projectDir,
        private LoggerInterface $logger
    ) {}

    #[Route('/users', name: 'api_users_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        // Generate unique export ID
        $exportId = Uuid::uuid4()->toString();
        
        // Get email from query parameters if provided
        $email = $request->query->get('email');
        
        // Get filters from query parameters
        $filters = array_filter($request->query->all(), function($key) {
            return !in_array($key, ['email']);
        }, ARRAY_FILTER_USE_KEY);

        // Dispatch message to generate users list
        $this->messageBus->dispatch(new GenerateUsersListMessage($exportId, $email, $filters));

        return new JsonResponse([
            'message' => 'Users list generation started',
            'export_id' => $exportId,
            'status_url' => '/api/users/export/' . $exportId,
            'download_url' => '/api/users/export/' . $exportId . '/download'
        ], Response::HTTP_ACCEPTED);
    }

    #[Route('/users/export/{id}', name: 'api_users_export_status', methods: ['GET'])]
    public function exportStatus(string $id): JsonResponse
    {
        $filename = $this->projectDir . '/public/exports/users_export_' . $id . '.json';
        
        if (!file_exists($filename)) {
            return new JsonResponse([
                'status' => 'processing',
                'message' => 'Export is being generated'
            ]);
        }

        return new JsonResponse([
            'status' => 'completed',
            'message' => 'Export is ready',
            'download_url' => '/api/users/export/' . $id . '/download'
        ]);
    }

    #[Route('/users/export/{id}/download', name: 'api_users_export_download', methods: ['GET'])]
    public function downloadExport(string $id): Response
    {
        $filename = $this->projectDir . '/public/exports/users_export_' . $id . '.json';
        
        if (!file_exists($filename)) {
            return new JsonResponse([
                'error' => 'Export not found'
            ], Response::HTTP_NOT_FOUND);
        }

        return new BinaryFileResponse($filename);
    }

    #[Route('/users', name: 'api_users_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        // Если данные - массив, обрабатываем массовое создание
        if (is_array($data) && isset($data[0])) {
            $users = [];
            foreach ($data as $userData) {
                $user = new User();
                $user->setUsername($userData['username']);
                $user->setEmail($userData['email']);
                $user->setName($userData['name']);
                $user->setAddress($userData['address'] ?? null);
                $user->setRoles([$userData['role'] ?? 'ROLE_USER']);
                $user->setPassword('temporary_password_' . uniqid()); // Временный пароль
                
                $this->entityManager->persist($user);
                $users[] = $user;
            }
            $this->entityManager->flush();

            // Отправляем сообщения для асинхронной обработки
            foreach ($users as $user) {
                $this->messageBus->dispatch(new ProcessUserMessage($user->getId()));
            }

            $responseData = $this->serializer->serialize($users, 'json', ['groups' => 'user:read']);
            return new JsonResponse(json_decode($responseData, true), Response::HTTP_CREATED);
        }
        
        // Одиночное создание пользователя
        $user = new User();
        $user->setUsername($data['username']);
        $user->setEmail($data['email']);
        $user->setName($data['name']);
        $user->setAddress($data['address'] ?? null);
        $user->setRoles([$data['role'] ?? 'ROLE_USER']);
        $user->setPassword('temporary_password_' . uniqid()); // Временный пароль
        
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        // Отправляем сообщение для асинхронной обработки
        $this->messageBus->dispatch(new ProcessUserMessage($user->getId()));

        $responseData = $this->serializer->serialize($user, 'json', ['groups' => 'user:read']);
        return new JsonResponse(json_decode($responseData, true), Response::HTTP_CREATED);
    }

    #[Route('/users/{id}', name: 'api_users_show', methods: ['GET'])]
    public function show(User $user): JsonResponse
    {
        $data = $this->serializer->serialize($user, 'json', ['groups' => 'user:read']);
        return new JsonResponse(json_decode($data, true));
    }

    #[Route('/users/import', name: 'api_users_import', methods: ['POST'])]
    public function import(Request $request): JsonResponse
    {
        $this->logger->info('Starting user import process');
        
        // Check if users table exists
        try {
            $this->entityManager->getConnection()->executeQuery('SELECT 1 FROM users LIMIT 1');
        } catch (\Exception $e) {
            $this->logger->error('Users table does not exist', ['error' => $e->getMessage()]);
            return new JsonResponse([
                'error' => 'Database table "users" does not exist. Please run database migrations first.',
                'details' => 'Run: php bin/console doctrine:migrations:migrate'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
        
        $data = json_decode($request->getContent(), true);
        $filename = $data['filename'] ?? null;
        
        if (!$filename) {
            $this->logger->error('Filename is missing in the request');
            return new JsonResponse(['error' => 'Filename is required'], Response::HTTP_BAD_REQUEST);
        }

        $filepath = $this->projectDir . '/import/' . $filename;
        $this->logger->info('Attempting to read file', ['filepath' => $filepath]);
        
        if (!file_exists($filepath)) {
            $this->logger->error('File not found', ['filepath' => $filepath]);
            return new JsonResponse(['error' => 'File not found in import directory'], Response::HTTP_NOT_FOUND);
        }

        // Generate unique import ID
        $importId = Uuid::uuid4()->toString();

        try {
            // Проверяем соединение с RabbitMQ
            $this->messageBus->dispatch(new ImportUsersMessage($filename));
            
            return new JsonResponse([
                'message' => 'Import process started. Users will be imported asynchronously.',
                'status' => 'processing',
                'import_id' => $importId,
                'status_url' => '/api/users/import/' . $importId . '/status'
            ], Response::HTTP_ACCEPTED);
        } catch (TransportException $e) {
            $this->logger->error('Failed to connect to RabbitMQ', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return new JsonResponse([
                'error' => 'Failed to connect to message queue. Please try again later.',
                'details' => 'RabbitMQ connection error'
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    #[Route('/users/import/{id}/status', name: 'api_users_import_status', methods: ['GET'])]
    public function importStatus(string $id): JsonResponse
    {
        $statusFile = $this->projectDir . '/var/import_status/' . $id . '.json';
        
        if (!file_exists($statusFile)) {
            return new JsonResponse([
                'status' => 'processing',
                'message' => 'Import is being processed'
            ]);
        }

        $status = json_decode(file_get_contents($statusFile), true);
        return new JsonResponse($status);
    }
} 