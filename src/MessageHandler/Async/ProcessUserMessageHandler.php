<?php

namespace App\MessageHandler\Async;

use App\Message\Async\ProcessUserMessage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class ProcessUserMessageHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private MailerInterface $mailer
    ) {}

    public function __invoke(ProcessUserMessage $message): void
    {
        $userId = $message->getUserId();
        $this->logger->info('Processing user', ['user_id' => $userId]);

        try {
            $user = $this->entityManager->getRepository(\App\Entity\User::class)->find($userId);
            if (!$user) {
                throw new \RuntimeException('User not found');
            }

            // Отправляем email
            $email = (new Email())
                ->from('noreply@example.com')
                ->to($user->getEmail())
                ->subject('Welcome to our platform!')
                ->html($this->getEmailTemplate($user));

            $this->mailer->send($email);
            $this->logger->info('Email sent successfully', ['user_id' => $userId]);

        } catch (\Exception $e) {
            $this->logger->error('Error processing user', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    private function getEmailTemplate(\App\Entity\User $user): string
    {
        return <<<HTML
            <h1>Welcome {$user->getName()}!</h1>
            <p>Thank you for joining our platform. Your account has been created successfully.</p>
            <p>Your username is: {$user->getUsername()}</p>
            <p>Best regards,<br>The Team</p>
        HTML;
    }
} 