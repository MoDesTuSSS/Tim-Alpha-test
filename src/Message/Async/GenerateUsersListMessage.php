<?php

namespace App\Message\Async;

class GenerateUsersListMessage extends BaseAsyncMessage
{
    public function __construct(
        private readonly string $exportId,
        private readonly ?string $email = null,
        private readonly array $filters = []
    ) {}

    public function getExportId(): string
    {
        return $this->exportId;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getFilters(): array
    {
        return $this->filters;
    }
} 