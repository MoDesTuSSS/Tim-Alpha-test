<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

#[Route('/api')]
class UserController extends AbstractController
{
    private EntityManagerInterface $entityManager;
    private MailerInterface $mailer;

    public function __construct(EntityManagerInterface $entityManager, MailerInterface $mailer)
    {
        $this->entityManager = $entityManager;
        $this->mailer = $mailer;
    }

    #[Route('/upload', name: 'api_upload_users', methods: ['POST'])]
    public function uploadUsers(Request $request): JsonResponse
    {
        $file = $request->files->get('file');

        if (!$file) {
            return new JsonResponse(['error' => 'No file uploaded'], Response::HTTP_BAD_REQUEST);
        }

        if ($file->getClientOriginalExtension() !== 'csv') {
            return new JsonResponse(['error' => 'File must be a CSV'], Response::HTTP_BAD_REQUEST);
        }

        // Validate file size (max 5MB)
        if ($file->getSize() > 5 * 1024 * 1024) {
            return new JsonResponse(['error' => 'File size exceeds 5MB limit'], Response::HTTP_BAD_REQUEST);
        }

        $handle = fopen($file->getPathname(), 'r');
        $header = fgetcsv($handle);
        
        // Validate CSV structure
        $requiredColumns = ['name', 'email', 'username', 'address', 'role'];
        if ($header !== $requiredColumns) {
            fclose($handle);
            return new JsonResponse([
                'error' => 'Invalid CSV structure. Required columns: ' . implode(', ', $requiredColumns)
            ], Response::HTTP_BAD_REQUEST);
        }

        $users = [];
        $line = 2; // Start from line 2 (after header)
        
        try {
            while (($data = fgetcsv($handle)) !== false) {
                if (count($data) !== count($requiredColumns)) {
                    throw new \RuntimeException("Invalid number of columns at line {$line}");
                }

                // Validate email
                if (!filter_var($data[1], FILTER_VALIDATE_EMAIL)) {
                    throw new \RuntimeException("Invalid email format at line {$line}");
                }

                // Validate role
                if (!in_array($data[4], ['ROLE_USER', 'ROLE_ADMIN'])) {
                    throw new \RuntimeException("Invalid role at line {$line}. Allowed roles: ROLE_USER, ROLE_ADMIN");
                }

                $user = new User();
                $user->setName($data[0]);
                $user->setEmail($data[1]);
                $user->setUsername($data[2]);
                $user->setAddress($data[3]);
                $user->setRoles([$data[4]]);

                $this->entityManager->persist($user);
                $users[] = $user;
                $line++;
            }

            $this->entityManager->flush();

            // Send email asynchronously
            foreach ($users as $user) {
                $this->sendWelcomeEmail($user);
            }

            return new JsonResponse([
                'message' => 'Users imported successfully',
                'count' => count($users)
            ], Response::HTTP_CREATED);

        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } finally {
            if (isset($handle)) {
                fclose($handle);
            }
        }
    }

    #[Route('/users', name: 'api_get_users', methods: ['GET'])]
    public function getUsers(): JsonResponse
    {
        $users = $this->entityManager->getRepository(User::class)->findAll();
        $data = [];

        foreach ($users as $user) {
            $data[] = [
                'id' => $user->getId(),
                'name' => $user->getName(),
                'email' => $user->getEmail(),
                'username' => $user->getUsername(),
                'address' => $user->getAddress(),
                'roles' => $user->getRoles()
            ];
        }

        return new JsonResponse($data);
    }

    private function sendWelcomeEmail(User $user): void
    {
        $email = (new Email())
            ->from('noreply@example.com')
            ->to($user->getEmail())
            ->subject('Welcome to our platform')
            ->html('<p>Welcome ' . $user->getName() . '! Your account has been created successfully.</p>');

        $this->mailer->send($email);
    }
} 