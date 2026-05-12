<?php

namespace App\Controller\Api;

use App\Entity\Role;
use App\Repository\RoleRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/roles')]
class RoleApiController extends AbstractController
{
    #[Route('', name: 'api_roles_index', methods: ['GET'])]
    public function index(RoleRepository $roleRepository): JsonResponse
    {
        $roles = $roleRepository->findBy([], ['id' => 'ASC']);

        return $this->json([
            'success' => true,
            'roles' => array_map([$this, 'serializeRole'], $roles),
        ]);
    }

    #[Route('', name: 'api_roles_create', methods: ['POST'])]
    public function create(
        Request $request,
        RoleRepository $roleRepository,
        EntityManagerInterface $em
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json([
                'success' => false,
                'message' => 'Invalid JSON body.',
            ], 400);
        }

        $name = strtoupper(trim((string) ($data['name'] ?? '')));

        if (mb_strlen($name) < 3) {
            return $this->json([
                'success' => false,
                'message' => 'Role name must be at least 3 characters.',
            ], 400);
        }

        if ($roleRepository->findOneBy(['name' => $name]) instanceof Role) {
            return $this->json([
                'success' => false,
                'message' => 'This role already exists.',
            ], 409);
        }

        $role = new Role();
        $role->setName($name);

        $em->persist($role);
        $em->flush();

        return $this->json([
            'success' => true,
            'message' => 'Role created successfully.',
            'role' => $this->serializeRole($role),
        ], 201);
    }

    #[Route('/{id}', name: 'api_roles_update', methods: ['PUT'])]
    public function update(
        int $id,
        Request $request,
        RoleRepository $roleRepository,
        EntityManagerInterface $em
    ): JsonResponse {
        $role = $roleRepository->find($id);

        if (!$role instanceof Role) {
            return $this->json([
                'success' => false,
                'message' => 'Role not found.',
            ], 404);
        }

        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json([
                'success' => false,
                'message' => 'Invalid JSON body.',
            ], 400);
        }

        $name = strtoupper(trim((string) ($data['name'] ?? '')));

        if (mb_strlen($name) < 3) {
            return $this->json([
                'success' => false,
                'message' => 'Role name must be at least 3 characters.',
            ], 400);
        }

        $existingRole = $roleRepository->findOneBy(['name' => $name]);

        if ($existingRole instanceof Role && $existingRole->getId() !== $role->getId()) {
            return $this->json([
                'success' => false,
                'message' => 'This role name already exists.',
            ], 409);
        }

        $role->setName($name);
        $em->flush();

        return $this->json([
            'success' => true,
            'message' => 'Role updated successfully.',
            'role' => $this->serializeRole($role),
        ]);
    }

    #[Route('/{id}', name: 'api_roles_delete', methods: ['DELETE'])]
    public function delete(
        int $id,
        RoleRepository $roleRepository,
        UserRepository $userRepository,
        EntityManagerInterface $em
    ): JsonResponse {
        $role = $roleRepository->find($id);

        if (!$role instanceof Role) {
            return $this->json([
                'success' => false,
                'message' => 'Role not found.',
            ], 404);
        }

        $usersCount = (int) $userRepository->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('u.role = :role')
            ->setParameter('role', $role)
            ->getQuery()
            ->getSingleScalarResult();

        if ($usersCount > 0) {
            return $this->json([
                'success' => false,
                'message' => 'Cannot delete this role because it is used by ' . $usersCount . ' user(s).',
            ], 400);
        }

        $em->remove($role);
        $em->flush();

        return $this->json([
            'success' => true,
            'message' => 'Role deleted successfully.',
        ]);
    }

    private function serializeRole(Role $role): array
    {
        return [
            'id' => $role->getId(),
            'name' => $role->getName(),
        ];
    }
}