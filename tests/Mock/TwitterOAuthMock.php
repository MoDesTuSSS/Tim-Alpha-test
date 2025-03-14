<?php

namespace App\Tests\Mock;

use Abraham\TwitterOAuth\TwitterOAuth;

class TwitterOAuthMock extends TwitterOAuth
{
    private int $lastHttpCode = 200;
    private array $lastBody = [];
    private array $responses = [];

    public function __construct()
    {
        // Пустой конструктор для тестов
    }

    public function setApiVersion(string $version): void
    {
        // Ничего не делаем в тестах
    }

    public function setTimeouts(int $connectTimeout, int $timeout): void
    {
        // Ничего не делаем в тестах
    }

    public function oauth($path, array $parameters = []): array
    {
        if ($path === 'oauth/request_token') {
            return [
                'oauth_token' => 'test_token',
                'oauth_token_secret' => 'test_token_secret',
                'oauth_callback_confirmed' => 'true'
            ];
        }

        if ($path === 'oauth/access_token') {
            return [
                'oauth_token' => 'test_access_token',
                'oauth_token_secret' => 'test_access_token_secret',
                'user_id' => '12345',
                'screen_name' => 'test_user'
            ];
        }

        return [];
    }

    public function url($path, array $parameters): string
    {
        return 'https://api.twitter.com/' . $path . '?' . http_build_query($parameters);
    }

    public function get($path, array $parameters = []): object
    {
        if ($path === 'account/verify_credentials') {
            return (object) [
                'id_str' => '12345',
                'screen_name' => 'test_user',
                'name' => 'Test User'
            ];
        }

        return (object) [];
    }

    public function getLastHttpCode(): int
    {
        return $this->lastHttpCode;
    }

    public function getLastBody(): array
    {
        return $this->lastBody;
    }

    public function setLastHttpCode(int $code): void
    {
        $this->lastHttpCode = $code;
    }

    public function setLastBody(array $body): void
    {
        $this->lastBody = $body;
    }
} 