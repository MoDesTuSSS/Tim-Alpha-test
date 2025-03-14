<?php

namespace App\MessageHandler\Async;

use App\Message\Async\GenerateUsersListMessage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use App\Entity\User;

#[AsMessageHandler]
class GenerateUsersListMessageHandler
{
    private const CHUNK_SIZE = 1000;
    private const EXPORT_DIR = 'public/exports';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SerializerInterface $serializer,
        private readonly LoggerInterface $logger,
        private readonly string $projectDir,
        private readonly ?MailerInterface $mailer = null
    ) {}

    public function __invoke(GenerateUsersListMessage $message): void
    {
        $exportId = $message->getExportId();
        $this->logger->info('Starting users list generation', [
            'export_id' => $exportId,
            'filters' => $message->getFilters()
        ]);

        try {
            $exportDir = $this->projectDir . '/' . self::EXPORT_DIR;
            if (!is_dir($exportDir)) {
                mkdir($exportDir, 0755, true);
            }

            $filename = sprintf('%s/users_export_%s.json', $exportDir, $exportId);
            $repository = $this->entityManager->getRepository(User::class);
            
            // Open file for writing
            $handle = fopen($filename, 'w');
            fwrite($handle, '[');
            
            $offset = 0;
            $firstChunk = true;
            
            do {
                $users = $repository->findBy(
                    $message->getFilters(),
                    ['id' => 'ASC'],
                    self::CHUNK_SIZE,
                    $offset
                );
                
                if (!$firstChunk && count($users) > 0) {
                    fwrite($handle, ',');
                }
                
                foreach ($users as $index => $user) {
                    $userData = $this->serializer->serialize($user, 'json', ['groups' => 'user:read']);
                    if ($index > 0) {
                        fwrite($handle, ',');
                    }
                    fwrite($handle, trim($userData, '[]'));
                }
                
                $offset += self::CHUNK_SIZE;
                $firstChunk = false;
                
                // Clear EntityManager to free memory
                $this->entityManager->clear();
                
            } while (count($users) === self::CHUNK_SIZE);
            
            fwrite($handle, ']');
            fclose($handle);

            $downloadUrl = sprintf('/exports/users_export_%s.json', $exportId);
            
            // Send email if requested
            if ($message->getEmail()) {
                $this->sendNotificationEmail(
                    $message->getEmail(),
                    $downloadUrl,
                    $exportId
                );
            }

            $this->logger->info('Users list generated successfully', [
                'export_id' => $exportId,
                'file' => $filename
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Error generating users list', [
                'export_id' => $exportId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function sendNotificationEmail(string $email, string $downloadUrl, string $exportId): void
    {
        if (!$this->mailer) {
            return;
        }

        $email = (new Email())
            ->from('noreply@example.com')
            ->to($email)
            ->subject('Users List Export Ready')
            ->html(sprintf(
                'Your users list export (ID: %s) is ready. You can download it here: <a href="%s">Download</a>',
                $exportId,
                $downloadUrl
            ));

        $this->mailer->send($email);
    }
} 