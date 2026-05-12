<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Repository\RoleRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/auth')]
class AuthApiController extends AbstractController
{
    #[Route('/login', name: 'api_auth_login', methods: ['POST'])]
    public function login(
        Request $request,
        UserRepository $userRepository,
        UserPasswordHasherInterface $passwordHasher
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json([
                'success' => false,
                'message' => 'Invalid JSON body.',
            ], 400);
        }

        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $password = (string) ($data['password'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json([
                'success' => false,
                'message' => 'Invalid email.',
            ], 400);
        }

        if (mb_strlen($password) < 6) {
            return $this->json([
                'success' => false,
                'message' => 'Password must be at least 6 characters.',
            ], 400);
        }

        $user = $userRepository->findOneBy([
            'email' => $email,
        ]);

        if (!$user instanceof User) {
            return $this->json([
                'success' => false,
                'message' => 'Wrong email or password.',
            ], 401);
        }

        if ($user->isBlocked()) {
            return $this->json([
                'success' => false,
                'message' => 'Your account has been blocked.',
            ], 403);
        }

        if (!$passwordHasher->isPasswordValid($user, $password)) {
            return $this->json([
                'success' => false,
                'message' => 'Wrong email or password.',
            ], 401);
        }

        return $this->json([
            'success' => true,
            'message' => 'Login successful.',
            'user' => $this->serializeUser($user),
        ]);
    }

    #[Route('/register', name: 'api_auth_register', methods: ['POST'])]
    public function register(
        Request $request,
        EntityManagerInterface $em,
        UserRepository $userRepository,
        RoleRepository $roleRepository,
        UserPasswordHasherInterface $passwordHasher
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json([
                'success' => false,
                'message' => 'Invalid JSON body.',
            ], 400);
        }

        $fullName = trim((string) ($data['fullName'] ?? ''));
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $password = (string) ($data['password'] ?? '');

        if (mb_strlen($fullName) < 3) {
            return $this->json([
                'success' => false,
                'message' => 'Full name must be at least 3 characters.',
            ], 400);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json([
                'success' => false,
                'message' => 'Invalid email.',
            ], 400);
        }

        if (mb_strlen($password) < 6) {
            return $this->json([
                'success' => false,
                'message' => 'Password must be at least 6 characters.',
            ], 400);
        }

        $existingUser = $userRepository->findOneBy([
            'email' => $email,
        ]);

        if ($existingUser instanceof User) {
            return $this->json([
                'success' => false,
                'message' => 'This email already exists.',
            ], 409);
        }

        $defaultRole = $roleRepository->findOneBy([
            'name' => 'USER',
        ]);

        if (!$defaultRole) {
            return $this->json([
                'success' => false,
                'message' => 'Default USER role was not found.',
            ], 500);
        }

        $user = new User();
        $user->setFullName($fullName);
        $user->setEmail($email);
        $user->setRole($defaultRole);
        $user->setIsBlocked(false);
        $user->setPassword(
            $passwordHasher->hashPassword($user, $password)
        );

        $em->persist($user);
        $em->flush();

        return $this->json([
            'success' => true,
            'message' => 'Account created successfully.',
            'user' => $this->serializeUser($user),
        ], 201);
    }

    #[Route('/me', name: 'api_auth_me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            return $this->json([
                'success' => false,
                'message' => 'Not authenticated.',
            ], 401);
        }

        return $this->json([
            'success' => true,
            'user' => $this->serializeUser($user),
        ]);
    }

    private function serializeUser(User $user): array
    {
        return [
            'id' => $user->getId(),
            'fullName' => $user->getFullName(),
            'email' => $user->getEmail(),
            'roleName' => $user->getRole()?->getName(),
            'roles' => $user->getRoles(),
            'isBlocked' => $user->isBlocked(),
        ];
    }
}