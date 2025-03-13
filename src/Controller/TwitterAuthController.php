<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Exception\TwitterAuthException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Annotation\Route;
use Abraham\TwitterOAuth\TwitterOAuth;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Controller for handling Twitter OAuth authentication flow.
 */
#[Route('/auth')]
final class TwitterAuthController extends AbstractController
{
    private const TWITTER_API_VERSION = '1.1';
    private const CONNECT_TIMEOUT = 10;
    private const TIMEOUT = 15;
    private const SESSION_OAUTH_TOKEN = 'oauth_token';
    private const SESSION_OAUTH_TOKEN_SECRET = 'oauth_token_secret';
    
    private TwitterOAuth $twitterOAuth;
    private SessionInterface $session;
    
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly string $twitterApiKey,
        private readonly string $twitterApiSecret,
        private readonly string $twitterCallbackUrl,
        private readonly string $twitterAccessToken,
        private readonly string $twitterAccessTokenSecret,
        private readonly RequestStack $requestStack,
        private readonly LoggerInterface $logger
    ) {
        $this->session = $this->requestStack->getSession();
    }

    /**
     * Creates and configures TwitterOAuth instance.
     *
     * @param string|null $oauthToken OAuth token
     * @param string|null $oauthTokenSecret OAuth token secret
     * @return TwitterOAuth
     */
    private function createTwitterOAuth(?string $oauthToken = null, ?string $oauthTokenSecret = null): TwitterOAuth
    {
        if (isset($this->container) && $this->container->has('test.twitter_oauth')) {
            return $this->container->get('test.twitter_oauth');
        }

        $connection = new TwitterOAuth(
            $this->twitterApiKey,
            $this->twitterApiSecret,
            $oauthToken,
            $oauthTokenSecret
        );
        
        $connection->setApiVersion(self::TWITTER_API_VERSION);
        $connection->setTimeouts(self::CONNECT_TIMEOUT, self::TIMEOUT);
        
        return $connection;
    }

    /**
     * Initiates Twitter OAuth authentication process.
     *
     * @throws TwitterAuthException When Twitter API request fails
     */
    #[Route('/twitter', name: 'auth_twitter', methods: ['GET'])]
    public function connect(): RedirectResponse
    {
        try {
            $connection = $this->createTwitterOAuth();
            
            $this->logDebugInfo();
            
            $requestToken = $this->getRequestToken($connection);
            
            $this->saveTokensToSession($requestToken);
            
            $authUrl = $this->generateAuthorizationUrl($connection, $requestToken);
            
            return new RedirectResponse($authUrl);
            
        } catch (\Exception $e) {
            $this->logger->error('Twitter authentication error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw new TwitterAuthException('Ошибка аутентификации Twitter', 0, $e);
        }
    }

    /**
     * Handles Twitter OAuth callback.
     *
     * @throws TwitterAuthException When authentication fails
     */
    #[Route('/twitter/callback', name: 'auth_twitter_callback', methods: ['GET', 'POST'])]
    public function callback(Request $request): Response
    {
        try {
            $oauthVerifier = $this->getOAuthVerifier($request);
            
            if (!$oauthVerifier) {
                return $this->renderPinForm();
            }

            $requestToken = $this->getStoredRequestToken();
            
            $connection = $this->createTwitterOAuth(
                $requestToken['oauth_token'],
                $requestToken['oauth_token_secret']
            );

            $accessToken = $this->getAccessToken($connection, $oauthVerifier);
            
            $twitterUser = $this->verifyCredentials($connection, $accessToken);
            
            $user = $this->updateOrCreateUser($twitterUser, $accessToken);

            $this->clearSessionTokens();

            return new RedirectResponse(
                'yourapp://auth?token=' . $accessToken['oauth_token'],
                Response::HTTP_FOUND
            );
            
        } catch (\Exception $e) {
            $this->logger->error('Twitter callback error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw new TwitterAuthException('Ошибка обработки callback: ' . $e->getMessage());
        }
    }

    /**
     * Logs debug information about the authentication process.
     */
    private function logDebugInfo(): void
    {
        $this->logger->debug('Starting Twitter OAuth process', [
            'api_key' => substr($this->twitterApiKey, 0, 5) . '...',
            'callback_url' => $this->twitterCallbackUrl,
            'php_version' => PHP_VERSION,
            'curl_version' => curl_version()['version'],
            'ssl_version' => curl_version()['ssl_version']
        ]);
    }

    /**
     * Gets request token from Twitter.
     *
     * @throws TwitterAuthException When request token cannot be obtained
     */
    private function getRequestToken(TwitterOAuth $connection): array
    {
        $requestToken = $connection->oauth('oauth/request_token', [
            'oauth_callback' => 'oob'
        ]);

        $this->logger->debug('Request token response', [
            'response' => $requestToken,
            'http_code' => $connection->getLastHttpCode()
        ]);

        if ($connection->getLastHttpCode() !== 200) {
            throw new TwitterAuthException(sprintf(
                'Ошибка получения request token. HTTP код: %d, Ответ: %s',
                $connection->getLastHttpCode(),
                json_encode($connection->getLastBody())
            ));
        }

        return $requestToken;
    }

    /**
     * Saves OAuth tokens to session.
     */
    private function saveTokensToSession(array $requestToken): void
    {
        $this->session->set(self::SESSION_OAUTH_TOKEN, $requestToken['oauth_token']);
        $this->session->set(self::SESSION_OAUTH_TOKEN_SECRET, $requestToken['oauth_token_secret']);
    }

    /**
     * Generates Twitter authorization URL.
     */
    private function generateAuthorizationUrl(TwitterOAuth $connection, array $requestToken): string
    {
        $url = $connection->url('oauth/authorize', [
            'oauth_token' => $requestToken['oauth_token']
        ]);

        $this->logger->debug('Generated authorization URL', ['url' => $url]);

        return $url;
    }

    /**
     * Gets OAuth verifier from request.
     */
    private function getOAuthVerifier(Request $request): ?string
    {
        return $request->query->get('oauth_verifier') ?? $request->request->get('pin');
    }

    /**
     * Renders PIN input form.
     */
    private function renderPinForm(): Response
    {
        return new Response(
            '<form method="POST" class="twitter-pin-form">
                <div class="form-group">
                    <label for="pin">Введите PIN-код, полученный от Twitter:</label><br>
                    <input type="text" id="pin" name="pin" class="form-control" required><br>
                    <button type="submit" class="btn btn-primary">Подтвердить</button>
                </div>
            </form>',
            Response::HTTP_OK
        );
    }

    /**
     * Gets stored request token from session.
     *
     * @throws TwitterAuthException When tokens are not found in session
     */
    private function getStoredRequestToken(): array
    {
        $token = $this->session->get(self::SESSION_OAUTH_TOKEN);
        $tokenSecret = $this->session->get(self::SESSION_OAUTH_TOKEN_SECRET);

        if (!$token || !$tokenSecret) {
            throw new TwitterAuthException('OAuth токены не найдены в сессии');
        }

        return [
            'oauth_token' => $token,
            'oauth_token_secret' => $tokenSecret
        ];
    }

    /**
     * Gets access token from Twitter.
     *
     * @throws TwitterAuthException When access token cannot be obtained
     */
    private function getAccessToken(TwitterOAuth $connection, string $oauthVerifier): array
    {
        $accessToken = $connection->oauth('oauth/access_token', [
            'oauth_verifier' => $oauthVerifier
        ]);

        if ($connection->getLastHttpCode() !== 200) {
            throw new TwitterAuthException('Не удалось получить access token');
        }

        return $accessToken;
    }

    /**
     * Verifies Twitter credentials.
     *
     * @throws TwitterAuthException When credentials verification fails
     */
    private function verifyCredentials(TwitterOAuth $connection, array $accessToken): object
    {
        $connection = $this->createTwitterOAuth(
            $accessToken['oauth_token'],
            $accessToken['oauth_token_secret']
        );

        $twitterUser = $connection->get('account/verify_credentials');

        if ($connection->getLastHttpCode() !== 200) {
            throw new TwitterAuthException('Не удалось верифицировать учетные данные Twitter');
        }

        return $twitterUser;
    }

    /**
     * Updates existing user or creates new one.
     */
    private function updateOrCreateUser(object $twitterUser, array $accessToken): User
    {
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

        return $user;
    }

    /**
     * Clears OAuth tokens from session.
     */
    private function clearSessionTokens(): void
    {
        $this->session->remove(self::SESSION_OAUTH_TOKEN);
        $this->session->remove(self::SESSION_OAUTH_TOKEN_SECRET);
    }
} 