<?php

namespace App\Controller;

use App\Entity\User;
use App\Message\Async\ProcessUserMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Serializer\SerializerInterface;

#[Route('/api')]
class UserController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private SerializerInterface $serializer,
        private MessageBusInterface $messageBus
    ) {}

    #[Route('/users', name: 'api_users_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $users = $this->entityManager->getRepository(User::class)->findAll();
        $data = $this->serializer->serialize($users, 'json', ['groups' => 'user:read']);
        return new JsonResponse(json_decode($data, true));
    }

    #[Route('/users', name: 'api_users_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        $user = new User();
        $user->setUsername($data['username']);
        $user->setEmail($data['email']);
        $user->setName($data['name']);
        
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        // Send message for asynchronous processing
        $this->messageBus->dispatch(new ProcessUserMessage($user->getId()));

        $responseData = $this->serializer->serialize($user, 'json', ['groups' => 'user:read']);
        return new JsonResponse(json_decode($responseData, true), Response::HTTP_CREATED);
    }

    #[Route('/users/{id}', name: 'api_users_show', methods: ['GET'])]
    public function show(User $user): JsonResponse
    {
        $data = $this->serializer->serialize($user, 'json', ['groups' => 'user:read']);
        return new JsonResponse(json_decode($data, true));
    }
} 