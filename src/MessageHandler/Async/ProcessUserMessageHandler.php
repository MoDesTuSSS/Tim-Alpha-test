<?php

namespace App\MessageHandler\Async;

use App\Message\Async\ProcessUserMessage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;

#[AsMessageHandler]
class ProcessUserMessageHandler implements MessageHandlerInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger
    ) {}

    public function __invoke(ProcessUserMessage $message): void
    {
        $userId = $message->getUserId();
        $this->logger->info('Processing user', ['user_id' => $userId]);

        try {
            // User processing logic will be here
            
            $this->logger->info('User processed successfully', ['user_id' => $userId]);
        } catch (\Exception $e) {
            $this->logger->error('Error processing user', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
} 