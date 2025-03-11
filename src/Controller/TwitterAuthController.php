<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Abraham\TwitterOAuth\TwitterOAuth;

#[Route('/auth')]
class TwitterAuthController extends AbstractController
{
    private EntityManagerInterface $entityManager;
    private string $twitterApiKey;
    private string $twitterApiSecret;
    private string $twitterCallbackUrl;
    private string $twitterAccessToken;
    private string $twitterAccessTokenSecret;

    public function __construct(
        EntityManagerInterface $entityManager,
        string $twitterApiKey,
        string $twitterApiSecret,
        string $twitterCallbackUrl,
        string $twitterAccessToken,
        string $twitterAccessTokenSecret
    ) {
        $this->entityManager = $entityManager;
        $this->twitterApiKey = $twitterApiKey;
        $this->twitterApiSecret = $twitterApiSecret;
        $this->twitterCallbackUrl = $twitterCallbackUrl;
        $this->twitterAccessToken = $twitterAccessToken;
        $this->twitterAccessTokenSecret = $twitterAccessTokenSecret;
    }

    protected function createTwitterOAuth(string $oauth_token = null, string $oauth_token_secret = null): TwitterOAuth
    {
        if (isset($this->container) && $this->container->has('test.twitter_oauth')) {
            return $this->container->get('test.twitter_oauth');
        }

        if ($oauth_token && $oauth_token_secret) {
            return new TwitterOAuth(
                $this->twitterApiKey,
                $this->twitterApiSecret,
                $oauth_token,
                $oauth_token_secret
            );
        }
        
        return new TwitterOAuth(
            $this->twitterApiKey,
            $this->twitterApiSecret,
            $this->twitterAccessToken,
            $this->twitterAccessTokenSecret
        );
    }

    #[Route('/twitter', name: 'auth_twitter')]
    public function connect(): RedirectResponse
    {
        $connection = $this->createTwitterOAuth();

        $requestToken = $connection->oauth('oauth/request_token', [
            'oauth_callback' => $this->twitterCallbackUrl
        ]);

        if ($connection->getLastHttpCode() != 200) {
            throw new \RuntimeException('There was a problem performing the request');
        }

        // Save tokens in session
        $session = $this->container->get('session');
        $session->set('oauth_token', $requestToken['oauth_token']);
        $session->set('oauth_token_secret', $requestToken['oauth_token_secret']);

        // Generate authorization URL
        $url = $connection->url('oauth/authorize', [
            'oauth_token' => $requestToken['oauth_token']
        ]);

        return new RedirectResponse($url);
    }

    #[Route('/twitter/callback', name: 'auth_twitter_callback')]
    public function callback(Request $request): Response
    {
        $session = $this->container->get('session');
        $requestToken = [
            'oauth_token' => $session->get('oauth_token'),
            'oauth_token_secret' => $session->get('oauth_token_secret')
        ];

        if ($request->query->get('oauth_token') !== $requestToken['oauth_token']) {
            throw new \RuntimeException('OAuth token mismatch');
        }

        $connection = $this->createTwitterOAuth(
            $requestToken['oauth_token'],
            $requestToken['oauth_token_secret']
        );

        $accessToken = $connection->oauth('oauth/access_token', [
            'oauth_verifier' => $request->query->get('oauth_verifier')
        ]);

        $connection = $this->createTwitterOAuth(
            $accessToken['oauth_token'],
            $accessToken['oauth_token_secret']
        );

        $twitterUser = $connection->get('account/verify_credentials');

        // Find existing user or create new one
        $user = $this->entityManager->getRepository(User::class)
            ->findOneBy(['twitterId' => $twitterUser->id]);

        if (!$user) {
            $user = new User();
            $user->setTwitterId($twitterUser->id);
            $user->setUsername($twitterUser->screen_name);
            $user->setName($twitterUser->name);
            $user->setRoles(['ROLE_USER']);
        }

        $user->setTwitterAccessToken($accessToken['oauth_token']);
        $user->setTwitterAccessTokenSecret($accessToken['oauth_token_secret']);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        // Clear session
        $session->remove('oauth_token');
        $session->remove('oauth_token_secret');

        // Redirect to app with token
        return new RedirectResponse('yourapp://auth?token=' . $accessToken['oauth_token']);
    }
} 