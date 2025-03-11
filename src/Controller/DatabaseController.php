<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\HttpKernel\KernelInterface;

#[Route('/api')]
class DatabaseController extends AbstractController
{
    private KernelInterface $kernel;

    public function __construct(KernelInterface $kernel)
    {
        $this->kernel = $kernel;
    }

    #[Route('/backup', name: 'api_backup_database', methods: ['GET'])]
    public function backup(): Response
    {
        try {
            $application = new Application($this->kernel);
            $application->setAutoExit(false);

            $input = new ArrayInput([
                'command' => 'app:database:backup',
            ]);

            $output = new BufferedOutput();
            $result = $application->run($input, $output);

            if ($result === 0) {
                // Find the latest backup file
                $backupDir = $this->kernel->getProjectDir() . '/var/backup';
                $files = glob($backupDir . '/backup_*.sql');
                $latestBackup = end($files);

                return $this->file(
                    $latestBackup,
                    basename($latestBackup),
                    ResponseHeaderBag::DISPOSITION_ATTACHMENT
                );
            }

            return new JsonResponse(
                ['error' => 'Failed to create backup: ' . $output->fetch()],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        } catch (\Exception $e) {
            return new JsonResponse(
                ['error' => 'Failed to create backup: ' . $e->getMessage()],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    #[Route('/restore', name: 'api_restore_database', methods: ['POST'])]
    public function restore(Request $request): JsonResponse
    {
        $file = $request->files->get('file');

        if (!$file) {
            return new JsonResponse(['error' => 'No file uploaded'], Response::HTTP_BAD_REQUEST);
        }

        if ($file->getClientOriginalExtension() !== 'sql') {
            return new JsonResponse(['error' => 'File must be a SQL dump'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $application = new Application($this->kernel);
            $application->setAutoExit(false);

            $input = new ArrayInput([
                'command' => 'app:database:restore',
                'file' => $file->getPathname(),
            ]);

            $output = new BufferedOutput();
            $result = $application->run($input, $output);

            if ($result === 0) {
                return new JsonResponse(['message' => 'Database restored successfully']);
            }

            return new JsonResponse(
                ['error' => 'Failed to restore database: ' . $output->fetch()],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        } catch (\Exception $e) {
            return new JsonResponse(
                ['error' => 'Failed to restore database: ' . $e->getMessage()],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }
} 