<?php

namespace App\Tests\Controller;

use App\Controller\TwitterAuthController;
use App\Entity\User;
use App\Exception\TwitterAuthException;
use App\Tests\Mock\TwitterOAuthMock;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\DependencyInjection\Container;
use Psr\Log\LoggerInterface;

class TwitterAuthControllerTest extends TestCase
{
    private EntityManagerInterface $entityManager;
    private TwitterAuthController $controller;
    private Session $session;
    private RequestStack $requestStack;
    private EntityRepository $userRepository;
    private Container $container;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        // Create Repository Stub
        $this->userRepository = new class extends EntityRepository {
            private ?User $user = null;
            
            public function __construct() {}
            
            public function setUser(?User $user): void 
            {
                $this->user = $user;
            }
            
            public function findOneBy(array $criteria, ?array $orderBy = null): ?User
            {
                return $this->user;
            }
        };
        
        // Create EntityManager Stub
        $this->entityManager = new class($this->userRepository) implements EntityManagerInterface {
            private EntityRepository $repository;
            private array $persistedEntities = [];
            
            public function __construct(EntityRepository $repository) 
            {
                $this->repository = $repository;
            }
            
            public function getRepository($className): EntityRepository
            {
                return $this->repository;
            }
            
            public function persist($entity): void
            {
                $this->persistedEntities[] = $entity;
            }
            
            public function flush(): void
            {
                // Do nothing in tests
            }
            
            public function getPersistedEntities(): array
            {
                return $this->persistedEntities;
            }
            
            // Other interface methods
            public function getCache() { }
            public function getConnection() { }
            public function getExpressionBuilder() { }
            public function beginTransaction() { }
            public function transactional($func) { }
            public function commit() { }
            public function rollback() { }
            public function clear($objectName = null) { }
            public function close() { }
            public function copy($entity, $deep = false) { }
            public function detach($entity) { }
            public function refresh($entity) { }
            public function remove($entity) { }
            public function merge($entity) { }
            public function lock($entity, $lockMode, $lockVersion = null) { }
            public function getClassMetadata($className) { }
            public function getMetadataFactory() { }
            public function initializeObject($obj) { }
            public function contains($entity) { }
            public function find($className, $id) { }
            public function createQuery($dql = '') { }
            public function createNamedQuery($name) { }
            public function createNativeQuery($sql, $rsm) { }
            public function createQueryBuilder() { }
            public function getReference($entityName, $id) { }
            public function getPartialReference($entityName, $identifier) { }
            public function isOpen() { return true; }
            public function getUnitOfWork() { }
            public function getHydrator($hydrationMode) { }
            public function newHydrator($hydrationMode) { }
            public function getProxyFactory() { }
            public function getFilters() { }
            public function isFiltersStateClean() { }
            public function hasFilters() { }
            public function getEventManager() { }
            public function createNamedNativeQuery($name)
            {
                throw new \RuntimeException('Not implemented');
            }
            
            public function getConfiguration()
            {
                throw new \RuntimeException('Not implemented');
            }
        };
        
        // Create real session with mock storage
        $this->session = new Session(new MockArraySessionStorage());
        
        // Create RequestStack and add Request with session
        $this->requestStack = new RequestStack();
        $request = new Request();
        $request->setSession($this->session);
        $this->requestStack->push($request);
        
        // Create logger
        $this->logger = $this->createMock(LoggerInterface::class);
        
        // Create container and add services
        $this->container = new Container();
        $this->container->set('session', $this->session);
        $this->container->set('request_stack', $this->requestStack);
        $this->container->set('test.twitter_oauth', new TwitterOAuthMock());
        
        // Create controller
        $this->controller = new TwitterAuthController(
            $this->entityManager,
            'test_api_key',
            'test_api_secret',
            'http://localhost:8080/auth/twitter/callback',
            'test_access_token',
            'test_access_token_secret',
            $this->requestStack,
            $this->logger
        );
        $this->controller->setContainer($this->container);
    }

    public function testConnect(): void
    {
        // Configure logger expectations
        $this->logger->expects($this->exactly(3))
            ->method('debug')
            ->withConsecutive(
                ['Starting Twitter OAuth process', $this->arrayHasKey('api_key')],
                ['Request token response', $this->arrayHasKey('response')],
                ['Generated authorization URL', $this->arrayHasKey('url')]
            );

        $response = $this->controller->connect();

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertStringContainsString('oauth/authorize', $response->getTargetUrl());
        
        // Check that tokens are saved in session
        $this->assertEquals('test_token', $this->session->get('oauth_token'));
        $this->assertEquals('test_token_secret', $this->session->get('oauth_token_secret'));
    }

    public function testCallbackWithPinForm(): void
    {
        // Create request without oauth_verifier
        $request = new Request();
        $request->setSession($this->session);

        $response = $this->controller->callback($request);

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertStringContainsString('twitter-pin-form', $response->getContent());
    }

    public function testCallbackWithPin(): void
    {
        // Prepare session
        $this->session->set('oauth_token', 'test_token');
        $this->session->set('oauth_token_secret', 'test_token_secret');

        // Create request with PIN code
        $request = new Request([], ['pin' => '123456']);
        $request->setSession($this->session);

        // Set that user is not found
        $this->userRepository->setUser(null);

        $response = $this->controller->callback($request);

        // Check that new user was created
        $persistedEntities = $this->entityManager->getPersistedEntities();
        $this->assertCount(1, $persistedEntities);
        $user = $persistedEntities[0];
        
        $this->assertInstanceOf(User::class, $user);
        $this->assertEquals('12345', $user->getTwitterId());
        $this->assertEquals('test_user', $user->getUsername());
        $this->assertEquals('Test User', $user->getName());
        $this->assertEquals(['ROLE_USER'], $user->getRoles());

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertStringContainsString('yourapp://auth?token=test_access_token', $response->getTargetUrl());
    }

    public function testCallbackWithExistingUser(): void
    {
        // Prepare session
        $this->session->set('oauth_token', 'test_token');
        $this->session->set('oauth_token_secret', 'test_token_secret');

        // Create request with oauth_verifier
        $request = new Request(['oauth_verifier' => 'test_verifier']);
        $request->setSession($this->session);

        // Create existing user
        $existingUser = new User();
        $existingUser->setTwitterId('12345');
        $existingUser->setUsername('existing_user');
        $existingUser->setName('Existing User');

        // Set existing user
        $this->userRepository->setUser($existingUser);

        $response = $this->controller->callback($request);

        // Check that user was updated
        $persistedEntities = $this->entityManager->getPersistedEntities();
        $this->assertCount(1, $persistedEntities);
        $user = $persistedEntities[0];
        
        $this->assertInstanceOf(User::class, $user);
        $this->assertEquals('12345', $user->getTwitterId());
        $this->assertEquals('test_access_token', $user->getTwitterAccessToken());
        $this->assertEquals('test_access_token_secret', $user->getTwitterAccessTokenSecret());

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertStringContainsString('yourapp://auth?token=test_access_token', $response->getTargetUrl());
    }

    public function testCallbackWithMissingTokens(): void
    {
        $this->expectException(TwitterAuthException::class);
        $this->expectExceptionMessage('OAuth tokens not found in session');

        // Create request without tokens in session
        $request = new Request(['oauth_verifier' => 'test_verifier']);
        $request->setSession($this->session);

        $this->controller->callback($request);
    }
} 