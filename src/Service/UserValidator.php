<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Psr\Log\LoggerInterface;

class UserValidator
{
    private const ALLOWED_ROLES = ['USER', 'ADMIN'];

    public function __construct(
        private ValidatorInterface $validator,
        private LoggerInterface $logger
    ) {}

    public function validateUserData(array $userData, int $rowNumber): array
    {
        $errors = [];

        // Check required fields
        $requiredFields = ['name', 'email', 'username', 'role'];
        foreach ($requiredFields as $field) {
            if (empty($userData[$field])) {
                $errors[] = "Field '$field' is required";
            }
        }

        // Validate email
        if (!empty($userData['email']) && !filter_var($userData['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Invalid email format: {$userData['email']}";
        }

        // Validate role
        if (!empty($userData['role']) && !in_array($userData['role'], self::ALLOWED_ROLES)) {
            $errors[] = "Invalid role: {$userData['role']}. Allowed roles: " . implode(', ', self::ALLOWED_ROLES);
        }

        // Валидация username
        if (!empty($userData['username']) && strlen($userData['username']) < 3) {
            $errors[] = "Имя пользователя должно содержать минимум 3 символа";
        }

        // Логирование ошибок валидации
        if (!empty($errors)) {
            $this->logger->warning('Ошибки валидации пользователя', [
                'row_number' => $rowNumber,
                'user_data' => $userData,
                'errors' => $errors
            ]);
        }

        return $errors;
    }
} 