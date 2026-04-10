<?php

namespace App\Controller\Admin;

use App\Entity\Role;
use App\Repository\RoleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/roles')]
class RoleController extends AbstractController
{
    #[Route('/', name: 'admin_roles', methods: ['GET'])]
    public function index(Request $request, RoleRepository $roleRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $search = trim((string) $request->query->get('search', ''));
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = 5;
        $offset = ($page - 1) * $limit;

        $qb = $roleRepository->createQueryBuilder('r');

        if ($search !== '') {
            $qb->andWhere('UPPER(r.name) LIKE UPPER(:search)')
               ->setParameter('search', '%' . $search . '%');
        }

        $total = (clone $qb)
            ->select('COUNT(r.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $roles = $qb
            ->orderBy('r.id', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $totalPages = max(1, (int) ceil($total / $limit));

        return $this->render('admin/roles/index.html.twig', [
            'roles' => $roles,
            'search' => $search,
            'page' => $page,
            'totalPages' => $totalPages,
        ]);
    }

    #[Route('/create', name: 'admin_roles_create', methods: ['POST'])]
    public function create(Request $request, EntityManagerInterface $em, RoleRepository $repo): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('create_role', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $name = strtoupper(trim((string) $request->request->get('name')));

        if ($name === '') {
            $this->addFlash('error', 'Role name is required.');
            return $this->redirectToRoute('admin_roles');
        }

        if (strlen($name) < 3) {
            $this->addFlash('error', 'Role name must be at least 3 characters.');
            return $this->redirectToRoute('admin_roles');
        }

        // 🔥 PROFESSIONAL VALIDATION
        if (!preg_match('/^[A-Z_]+$/', $name)) {
            $this->addFlash('error', 'Role name must contain only uppercase letters and underscores.');
            return $this->redirectToRoute('admin_roles');
        }

        $existing = $repo->findOneBy(['name' => $name]);
        if ($existing) {
            $this->addFlash('error', 'Role already exists.');
            return $this->redirectToRoute('admin_roles');
        }

        $role = new Role();
        $role->setName($name);

        $em->persist($role);
        $em->flush();

        $this->addFlash('success', 'Role created successfully.');
        return $this->redirectToRoute('admin_roles');
    }

    #[Route('/update/{id}', name: 'admin_roles_update', methods: ['POST'])]
    public function update(Role $role, Request $request, EntityManagerInterface $em, RoleRepository $repo): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('update_role_' . $role->getId(), $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $name = strtoupper(trim((string) $request->request->get('name')));

        if ($name === '') {
            $this->addFlash('error', 'Role name is required.');
            return $this->redirectToRoute('admin_roles');
        }

        if (strlen($name) < 3) {
            $this->addFlash('error', 'Role name must be at least 3 characters.');
            return $this->redirectToRoute('admin_roles');
        }

        // 🔥 PROFESSIONAL VALIDATION
        if (!preg_match('/^[A-Z_]+$/', $name)) {
            $this->addFlash('error', 'Role name must contain only uppercase letters and underscores.');
            return $this->redirectToRoute('admin_roles');
        }

        $existing = $repo->findOneBy(['name' => $name]);
        if ($existing && $existing->getId() !== $role->getId()) {
            $this->addFlash('error', 'Another role already uses this name.');
            return $this->redirectToRoute('admin_roles');
        }

        $role->setName($name);
        $em->flush();

        $this->addFlash('success', 'Role updated successfully.');
        return $this->redirectToRoute('admin_roles');
    }

    #[Route('/delete/{id}', name: 'admin_roles_delete', methods: ['POST'])]
    public function delete(Request $request, Role $role, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('delete_role_' . $role->getId(), $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if (count($role->getUsers()) > 0) {
            $this->addFlash('error', 'Cannot delete a role that is assigned to users.');
            return $this->redirectToRoute('admin_roles');
        }

        $em->remove($role);
        $em->flush();

        $this->addFlash('success', 'Role deleted successfully.');
        return $this->redirectToRoute('admin_roles');
    }
}