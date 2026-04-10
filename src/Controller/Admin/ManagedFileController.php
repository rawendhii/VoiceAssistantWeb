<?php

namespace App\Controller\Admin;

use App\Entity\ManagedFile;
use App\Repository\ManagedFileRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/managed-files')]
class ManagedFileController extends AbstractController
{
    #[Route('/', name: 'admin_managed_files', methods: ['GET'])]
    public function index(
        Request $request,
        ManagedFileRepository $managedFileRepository,
        UserRepository $userRepository
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $search = trim((string) $request->query->get('search', ''));
        $userFilter = trim((string) $request->query->get('user', ''));
        $typeFilter = trim((string) $request->query->get('type', ''));
        $page = max(1, (int) $request->query->get('page', 1));

        $limit = 5;
        $offset = ($page - 1) * $limit;

        $qb = $managedFileRepository->createQueryBuilder('mf')
            ->leftJoin('mf.user', 'u')
            ->addSelect('u');

        if ($search !== '') {
            $qb->andWhere('UPPER(mf.fileName) LIKE UPPER(:search) OR UPPER(mf.filePath) LIKE UPPER(:search) OR UPPER(mf.fileType) LIKE UPPER(:search)')
               ->setParameter('search', '%' . $search . '%');
        }

        if ($userFilter !== '') {
            $qb->andWhere('u.id = :userId')
               ->setParameter('userId', $userFilter);
        }

        if ($typeFilter !== '') {
            $qb->andWhere('UPPER(mf.fileType) = UPPER(:type)')
               ->setParameter('type', $typeFilter);
        }

        $total = (clone $qb)
            ->select('COUNT(mf.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $files = $qb
            ->orderBy('mf.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $totalPages = max(1, (int) ceil($total / $limit));

        return $this->render('admin/managed_files/index.html.twig', [
            'files' => $files,
            'users' => $userRepository->findBy([], ['id' => 'ASC']),
            'search' => $search,
            'userFilter' => $userFilter,
            'typeFilter' => $typeFilter,
            'page' => $page,
            'totalPages' => $totalPages,
        ]);
    }

    #[Route('/create', name: 'admin_managed_files_create', methods: ['GET'])]
    public function create(UserRepository $userRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        return $this->render('admin/managed_files/create.html.twig', [
            'users' => $userRepository->findBy([], ['id' => 'ASC']),
        ]);
    }

    #[Route('/store', name: 'admin_managed_files_store', methods: ['POST'])]
    public function store(
        Request $request,
        EntityManagerInterface $em,
        UserRepository $userRepository
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('create_managed_file', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $user = $userRepository->find($request->request->get('user'));
        $fileName = trim((string) $request->request->get('fileName'));
        $filePath = trim((string) $request->request->get('filePath'));
        $fileType = trim((string) $request->request->get('fileType'));

        if (!$user) {
            $this->addFlash('error', 'Selected user not found.');
            return $this->redirectToRoute('admin_managed_files_create');
        }

        if ($fileName === '' || $filePath === '' || $fileType === '') {
            $this->addFlash('error', 'All fields are required.');
            return $this->redirectToRoute('admin_managed_files_create');
        }

        $file = new ManagedFile();
        $file->setUser($user);
        $file->setFileName($fileName);
        $file->setFilePath($filePath);
        $file->setFileType(strtoupper($fileType));
        $file->setCreatedAt(new \DateTimeImmutable());
        $file->setUpdatedAt(new \DateTimeImmutable());

        $em->persist($file);
        $em->flush();

        $this->addFlash('success', 'Managed file created successfully.');
        return $this->redirectToRoute('admin_managed_files');
    }

    #[Route('/edit/{id}', name: 'admin_managed_files_edit', methods: ['GET'])]
    public function edit(ManagedFile $file, UserRepository $userRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        return $this->render('admin/managed_files/edit.html.twig', [
            'file' => $file,
            'users' => $userRepository->findBy([], ['id' => 'ASC']),
        ]);
    }

    #[Route('/update/{id}', name: 'admin_managed_files_update', methods: ['POST'])]
    public function update(
        ManagedFile $file,
        Request $request,
        EntityManagerInterface $em,
        UserRepository $userRepository
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('update_managed_file_' . $file->getId(), $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $user = $userRepository->find($request->request->get('user'));
        $fileName = trim((string) $request->request->get('fileName'));
        $filePath = trim((string) $request->request->get('filePath'));
        $fileType = trim((string) $request->request->get('fileType'));

        if (!$user) {
            $this->addFlash('error', 'Selected user not found.');
            return $this->redirectToRoute('admin_managed_files_edit', ['id' => $file->getId()]);
        }

        if ($fileName === '' || $filePath === '' || $fileType === '') {
            $this->addFlash('error', 'All fields are required.');
            return $this->redirectToRoute('admin_managed_files_edit', ['id' => $file->getId()]);
        }

        $file->setUser($user);
        $file->setFileName($fileName);
        $file->setFilePath($filePath);
        $file->setFileType(strtoupper($fileType));
        $file->setUpdatedAt(new \DateTimeImmutable());

        $em->flush();

        $this->addFlash('success', 'Managed file updated successfully.');
        return $this->redirectToRoute('admin_managed_files');
    }

    #[Route('/delete/{id}', name: 'admin_managed_files_delete', methods: ['POST'])]
    public function delete(Request $request, ManagedFile $file, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('delete_managed_file_' . $file->getId(), $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $em->remove($file);
        $em->flush();

        $this->addFlash('success', 'Managed file deleted successfully.');
        return $this->redirectToRoute('admin_managed_files');
    }
}