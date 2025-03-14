<?php

namespace App\Message\Async;

class ProcessUserMessage extends BaseAsyncMessage
{
    private int $userId;

    public function __construct(int $userId, array $data = [])
    {
        parent::__construct($data);
        $this->userId = $userId;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }
} 