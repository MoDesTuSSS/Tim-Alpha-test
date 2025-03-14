<?php

namespace App\Message\Async;

abstract class BaseAsyncMessage
{
    protected array $data;

    public function __construct(array $data = [])
    {
        $this->data = $data;
    }

    public function getData(): array
    {
        return $this->data;
    }
} 