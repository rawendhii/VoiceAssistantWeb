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

#[Route('/api/users')]
class UserApiController extends AbstractController
{
    #[Route('', name: 'api_users_index', methods: ['GET'])]
    public function index(Request $request, UserRepository $userRepository): JsonResponse
    {
        $search = trim((string) $request->query->get('search', ''));

        if ($search !== '') {
            $qb = $userRepository->createQueryBuilder('u')
                ->leftJoin('u.role', 'r')
                ->addSelect('r')
                ->where('LOWER(u.fullName) LIKE :search')
                ->orWhere('LOWER(u.email) LIKE :search')
                ->orWhere('LOWER(r.name) LIKE :search')
                ->setParameter('search', '%' . strtolower($search) . '%')
                ->orderBy('u.id', 'ASC');

            $users = $qb->getQuery()->getResult();
        } else {
            $users = $userRepository->createQueryBuilder('u')
                ->leftJoin('u.role', 'r')
                ->addSelect('r')
                ->orderBy('u.id', 'ASC')
                ->getQuery()
                ->getResult();
        }

        return $this->json([
            'success' => true,
            'users' => array_map([$this, 'serializeUser'], $users),
        ]);
    }

    #[Route('', name: 'api_users_create', methods: ['POST'])]
    public function create(
        Request $request,
        UserRepository $userRepository,
        RoleRepository $roleRepository,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $em
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
        $roleId = (int) ($data['roleId'] ?? 0);

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

        if ($userRepository->findOneBy(['email' => $email]) instanceof User) {
            return $this->json([
                'success' => false,
                'message' => 'This email already exists.',
            ], 409);
        }

        $role = $roleRepository->find($roleId);

        if (!$role) {
            return $this->json([
                'success' => false,
                'message' => 'Selected role was not found.',
            ], 404);
        }

        $user = new User();
        $user->setFullName($fullName);
        $user->setEmail($email);
        $user->setRole($role);
        $user->setIsBlocked(false);
        $user->setPassword($passwordHasher->hashPassword($user, $password));

        $em->persist($user);
        $em->flush();

        return $this->json([
            'success' => true,
            'message' => 'User created successfully.',
            'user' => $this->serializeUser($user),
        ], 201);
    }

    #[Route('/{id}', name: 'api_users_update', methods: ['PUT'])]
    public function update(
        int $id,
        Request $request,
        UserRepository $userRepository,
        RoleRepository $roleRepository,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $em
    ): JsonResponse {
        $user = $userRepository->find($id);

        if (!$user instanceof User) {
            return $this->json([
                'success' => false,
                'message' => 'User not found.',
            ], 404);
        }

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
        $roleId = (int) ($data['roleId'] ?? 0);

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

        $existingUser = $userRepository->findOneBy(['email' => $email]);

        if ($existingUser instanceof User && $existingUser->getId() !== $user->getId()) {
            return $this->json([
                'success' => false,
                'message' => 'This email is already used by another user.',
            ], 409);
        }

        $role = $roleRepository->find($roleId);

        if (!$role) {
            return $this->json([
                'success' => false,
                'message' => 'Selected role was not found.',
            ], 404);
        }

        $currentRoleName = strtoupper((string) $user->getRole()?->getName());
        $newRoleName = strtoupper((string) $role->getName());

        if ($currentRoleName === 'ADMIN' && $newRoleName !== 'ADMIN') {
            $adminCount = $this->countAdmins($userRepository);

            if ($adminCount <= 1) {
                return $this->json([
                    'success' => false,
                    'message' => 'You cannot change the last admin to a non-admin role.',
                ], 400);
            }
        }

        $user->setFullName($fullName);
        $user->setEmail($email);
        $user->setRole($role);

        if ($password !== '') {
            if (mb_strlen($password) < 6) {
                return $this->json([
                    'success' => false,
                    'message' => 'Password must be at least 6 characters.',
                ], 400);
            }

            $user->setPassword($passwordHasher->hashPassword($user, $password));
        }

        $em->flush();

        return $this->json([
            'success' => true,
            'message' => 'User updated successfully.',
            'user' => $this->serializeUser($user),
        ]);
    }
    #[Route('/{id}/password', name: 'api_users_change_password', methods: ['PUT'])]
public function changePassword(
    int $id,
    Request $request,
    UserRepository $userRepository,
    UserPasswordHasherInterface $passwordHasher,
    EntityManagerInterface $em
): JsonResponse {
    $user = $userRepository->find($id);

    if (!$user instanceof User) {
        return $this->json([
            'success' => false,
            'message' => 'User not found.',
        ], 404);
    }

    $data = json_decode($request->getContent(), true);

    if (!is_array($data)) {
        return $this->json([
            'success' => false,
            'message' => 'Invalid JSON body.',
        ], 400);
    }

    $currentPassword = (string) ($data['currentPassword'] ?? '');
    $newPassword = (string) ($data['newPassword'] ?? '');

    if (mb_strlen($newPassword) < 6) {
        return $this->json([
            'success' => false,
            'message' => 'New password must be at least 6 characters.',
        ], 400);
    }

    if (!$passwordHasher->isPasswordValid($user, $currentPassword)) {
        return $this->json([
            'success' => false,
            'message' => 'Current password is incorrect.',
        ], 400);
    }

    $user->setPassword(
        $passwordHasher->hashPassword($user, $newPassword)
    );

    $em->flush();

    return $this->json([
        'success' => true,
        'message' => 'Password changed successfully.',
    ]);
}

    #[Route('/{id}', name: 'api_users_delete', methods: ['DELETE'])]
    public function delete(
        int $id,
        UserRepository $userRepository,
        EntityManagerInterface $em
    ): JsonResponse {
        $user = $userRepository->find($id);

        if (!$user instanceof User) {
            return $this->json([
                'success' => false,
                'message' => 'User not found.',
            ], 404);
        }

        $roleName = strtoupper((string) $user->getRole()?->getName());

        if ($roleName === 'ADMIN') {
            $adminCount = $this->countAdmins($userRepository);

            if ($adminCount <= 1) {
                return $this->json([
                    'success' => false,
                    'message' => 'Cannot delete the last ADMIN. Please create another ADMIN first.',
                ], 400);
            }
        }

        $em->remove($user);
        $em->flush();

        return $this->json([
            'success' => true,
            'message' => 'User deleted successfully.',
        ]);
    }

    private function serializeUser(User $user): array
    {
        return [
            'id' => $user->getId(),
            'fullName' => $user->getFullName(),
            'email' => $user->getEmail(),
            'roleId' => $user->getRole()?->getId(),
            'roleName' => $user->getRole()?->getName(),
            'isBlocked' => $user->isBlocked(),
        ];
    }

    private function countAdmins(UserRepository $userRepository): int
    {
        return (int) $userRepository->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->join('u.role', 'r')
            ->where('UPPER(r.name) = :admin')
            ->setParameter('admin', 'ADMIN')
            ->getQuery()
            ->getSingleScalarResult();
    }
}