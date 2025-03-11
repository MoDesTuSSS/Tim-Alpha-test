<?php

namespace App\Tests\Mock;

use Abraham\TwitterOAuth\TwitterOAuth;

class TwitterOAuthMock extends TwitterOAuth
{
    public function __construct()
    {
        // Пустой конструктор для мока
    }

    public function oauth(string $path, array $parameters = []): array
    {
        if ($path === 'oauth/request_token') {
            return [
                'oauth_token' => 'test_token',
                'oauth_token_secret' => 'test_token_secret'
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

    public function url(string $path, array $parameters = []): string
    {
        return 'https://api.twitter.com/' . $path . '?' . http_build_query($parameters);
    }

    public function get(string $path, array $parameters = []): object
    {
        if ($path === 'account/verify_credentials') {
            $user = new \stdClass();
            $user->id = '12345';
            $user->screen_name = 'test_user';
            $user->name = 'Test User';
            return $user;
        }
        return new \stdClass();
    }

    public function getLastHttpCode(): int
    {
        return 200;
    }
} 