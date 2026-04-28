<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Repository\RoleRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/users')]
class UserController extends AbstractController
{
    #[Route('/', name: 'admin_users', methods: ['GET'])]
    public function index(
        Request $request,
        UserRepository $userRepository,
        RoleRepository $roleRepository
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $search = trim((string) $request->query->get('search', ''));
        $roleFilter = trim((string) $request->query->get('role', ''));
        $page = max(1, (int) $request->query->get('page', 1));

        $limit = 5;
        $offset = ($page - 1) * $limit;

        $qb = $userRepository->createQueryBuilder('u')
            ->leftJoin('u.role', 'r')
            ->addSelect('r');

        if ($search !== '') {
            $qb->andWhere('UPPER(u.fullName) LIKE UPPER(:search) OR UPPER(u.email) LIKE UPPER(:search)')
               ->setParameter('search', '%' . $search . '%');
        }

        if ($roleFilter !== '') {
            $qb->andWhere('r.id = :roleId')
               ->setParameter('roleId', $roleFilter);
        }

        $total = (clone $qb)
            ->select('COUNT(u.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $users = $qb
            ->orderBy('u.id', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $totalPages = max(1, (int) ceil($total / $limit));

        return $this->render('admin/users/index.html.twig', [
            'users' => $users,
            'roles' => $roleRepository->findBy([], ['id' => 'ASC']),
            'search' => $search,
            'roleFilter' => $roleFilter,
            'page' => $page,
            'totalPages' => $totalPages,
        ]);
    }

    #[Route('/create', name: 'admin_users_create', methods: ['GET'])]
    public function create(RoleRepository $roleRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        return $this->render('admin/users/create.html.twig', [
            'roles' => $roleRepository->findBy([], ['id' => 'ASC']),
        ]);
    }

    #[Route('/store', name: 'admin_users_store', methods: ['POST'])]
    public function store(
        Request $request,
        EntityManagerInterface $em,
        UserRepository $userRepository,
        RoleRepository $roleRepository,
        UserPasswordHasherInterface $passwordHasher
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('create_user', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $fullName = trim((string) $request->request->get('fullName'));
        $email = trim((string) $request->request->get('email'));
        $password = trim((string) $request->request->get('password'));
        $roleId = $request->request->get('role');

        if (strlen($fullName) < 3) {
            $this->addFlash('error', 'Full name must be at least 3 characters.');
            return $this->redirectToRoute('admin_users_create');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->addFlash('error', 'Invalid email address.');
            return $this->redirectToRoute('admin_users_create');
        }

        if (strlen($password) < 6) {
            $this->addFlash('error', 'Password must be at least 6 characters.');
            return $this->redirectToRoute('admin_users_create');
        }

        if ($userRepository->findOneBy(['email' => $email])) {
            $this->addFlash('error', 'This email already exists.');
            return $this->redirectToRoute('admin_users_create');
        }

        $role = $roleRepository->find($roleId);
        if (!$role) {
            $this->addFlash('error', 'Selected role not found.');
            return $this->redirectToRoute('admin_users_create');
        }

        $user = new User();
        $user->setFullName($fullName);
        $user->setEmail($email);
        $user->setRole($role);
        $user->setPassword($passwordHasher->hashPassword($user, $password));

        $em->persist($user);
        $em->flush();

        $this->addFlash('success', 'User created successfully.');
        return $this->redirectToRoute('admin_users');
    }

    #[Route('/edit/{id}', name: 'admin_users_edit', methods: ['GET'])]
    public function edit(User $user, RoleRepository $roleRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        return $this->render('admin/users/edit.html.twig', [
            'user' => $user,
            'roles' => $roleRepository->findBy([], ['id' => 'ASC']),
        ]);
    }

    #[Route('/update/{id}', name: 'admin_users_update', methods: ['POST'])]
    public function update(
        User $user,
        Request $request,
        EntityManagerInterface $em,
        UserRepository $userRepository,
        RoleRepository $roleRepository,
        UserPasswordHasherInterface $passwordHasher
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('update_user_' . $user->getId(), $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $fullName = trim((string) $request->request->get('fullName'));
        $email = trim((string) $request->request->get('email'));
        $password = trim((string) $request->request->get('password'));
        $roleId = $request->request->get('role');

        if (strlen($fullName) < 3) {
            $this->addFlash('error', 'Full name must be at least 3 characters.');
            return $this->redirectToRoute('admin_users_edit', ['id' => $user->getId()]);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->addFlash('error', 'Invalid email address.');
            return $this->redirectToRoute('admin_users_edit', ['id' => $user->getId()]);
        }

        $existing = $userRepository->findOneBy(['email' => $email]);
        if ($existing && $existing->getId() !== $user->getId()) {
            $this->addFlash('error', 'This email is already used by another user.');
            return $this->redirectToRoute('admin_users_edit', ['id' => $user->getId()]);
        }

        $role = $roleRepository->find($roleId);
        if (!$role) {
            $this->addFlash('error', 'Selected role not found.');
            return $this->redirectToRoute('admin_users_edit', ['id' => $user->getId()]);
        }

        // Prevent removing last admin
        $oldRole = strtoupper($user->getRole()?->getName() ?? '');
        $newRole = strtoupper($role->getName());

        if ($oldRole === 'ADMIN' && $newRole !== 'ADMIN') {
            if ($userRepository->countByRoleName('ADMIN') <= 1) {
                $this->addFlash('error', 'You cannot change the last admin.');
                return $this->redirectToRoute('admin_users_edit', ['id' => $user->getId()]);
            }
        }

        $user->setFullName($fullName);
        $user->setEmail($email);
        $user->setRole($role);

        if ($password !== '') {
            if (strlen($password) < 6) {
                $this->addFlash('error', 'Password must be at least 6 characters.');
                return $this->redirectToRoute('admin_users_edit', ['id' => $user->getId()]);
            }

            $user->setPassword($passwordHasher->hashPassword($user, $password));
        }

        $em->flush();

        $this->addFlash('success', 'User updated successfully.');
        return $this->redirectToRoute('admin_users');
    }

    #[Route('/delete/{id}', name: 'admin_users_delete', methods: ['POST'])]
    public function delete(
        Request $request,
        User $user,
        EntityManagerInterface $em,
        UserRepository $userRepository
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('delete_user_' . $user->getId(), $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $currentUser = $this->getUser();

        if ($currentUser instanceof User && $currentUser->getId() === $user->getId()) {
            $this->addFlash('error', 'You cannot delete your own account.');
            return $this->redirectToRoute('admin_users');
        }

        if (strtoupper($user->getRole()?->getName() ?? '') === 'ADMIN') {
            if ($userRepository->countByRoleName('ADMIN') <= 1) {
                $this->addFlash('error', 'Cannot delete the last admin.');
                return $this->redirectToRoute('admin_users');
            }
        }

        $em->remove($user);
        $em->flush();

        $this->addFlash('success', 'User deleted successfully.');
        return $this->redirectToRoute('admin_users');
    }
    #[Route('/admin/users/{id}/block', name: 'admin_users_block', methods: ['POST'])]
public function block(User $user, Request $request, EntityManagerInterface $em): Response
{
    if (!$this->isCsrfTokenValid('block_user_' . $user->getId(), $request->request->get('_token'))) {
        $this->addFlash('error', 'Invalid security token.');
        return $this->redirectToRoute('admin_users');
    }

    if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
        $this->addFlash('error', 'You cannot block an administrator.');
        return $this->redirectToRoute('admin_users');
    }

    $user->setIsBlocked(true);
    $em->flush();

    $this->addFlash('success', 'User blocked successfully.');
    return $this->redirectToRoute('admin_users');
}

#[Route('/admin/users/{id}/unblock', name: 'admin_users_unblock', methods: ['POST'])]
public function unblock(User $user, Request $request, EntityManagerInterface $em): Response
{
    if (!$this->isCsrfTokenValid('unblock_user_' . $user->getId(), $request->request->get('_token'))) {
        $this->addFlash('error', 'Invalid security token.');
        return $this->redirectToRoute('admin_users');
    }

    $user->setIsBlocked(false);
    $em->flush();

    $this->addFlash('success', 'User unblocked successfully.');
    return $this->redirectToRoute('admin_users');
}
}