<?php

namespace App\Message\Async;

class ImportUsersMessage extends BaseAsyncMessage
{
    private string $filename;

    public function __construct(string $filename, array $data = [])
    {
        parent::__construct($data);
        $this->filename = $filename;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }
} 