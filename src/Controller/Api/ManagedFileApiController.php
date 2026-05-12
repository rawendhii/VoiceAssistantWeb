<?php

namespace App\Controller\Api;

use App\Entity\ManagedFile;
use App\Entity\User;
use App\Repository\ManagedFileRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/managed-files')]
class ManagedFileApiController extends AbstractController
{
    #[Route('', name: 'api_managed_files_index', methods: ['GET'])]
    public function index(Request $request, ManagedFileRepository $managedFileRepository): JsonResponse
    {
        $search = trim((string) $request->query->get('search', ''));
        $type = trim((string) $request->query->get('type', 'ALL'));
        $userId = (int) $request->query->get('userId', 0);

        $qb = $managedFileRepository->createQueryBuilder('mf')
            ->leftJoin('mf.user', 'u')
            ->addSelect('u');

        if ($userId > 0) {
            $qb->andWhere('u.id = :userId')
                ->setParameter('userId', $userId);
        }

        if ($search !== '') {
            $qb->andWhere(
                'LOWER(u.fullName) LIKE :search 
                 OR LOWER(mf.fileName) LIKE :search 
                 OR LOWER(mf.filePath) LIKE :search 
                 OR LOWER(mf.fileType) LIKE :search'
            )
            ->setParameter('search', '%' . strtolower($search) . '%');
        }

        if ($type !== '' && strtoupper($type) !== 'ALL') {
            $qb->andWhere('LOWER(mf.fileType) = :type')
                ->setParameter('type', strtolower($type));
        }

        $files = $qb->orderBy('mf.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $this->json([
            'success' => true,
            'files' => array_map([$this, 'serializeManagedFile'], $files),
        ]);
    }

    #[Route('/{id}', name: 'api_managed_files_show', methods: ['GET'])]
    public function show(int $id, ManagedFileRepository $managedFileRepository): JsonResponse
    {
        $file = $managedFileRepository->createQueryBuilder('mf')
            ->leftJoin('mf.user', 'u')
            ->addSelect('u')
            ->where('mf.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$file instanceof ManagedFile) {
            return $this->json([
                'success' => false,
                'message' => 'Managed file not found.',
            ], 404);
        }

        return $this->json([
            'success' => true,
            'file' => $this->serializeManagedFile($file),
        ]);
    }

    #[Route('', name: 'api_managed_files_create', methods: ['POST'])]
    public function create(
        Request $request,
        UserRepository $userRepository,
        ManagedFileRepository $managedFileRepository,
        EntityManagerInterface $em
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json([
                'success' => false,
                'message' => 'Invalid JSON body.',
            ], 400);
        }

        $userId = (int) ($data['userId'] ?? 0);
        $fileName = trim((string) ($data['fileName'] ?? ''));
        $filePath = trim((string) ($data['filePath'] ?? ''));
        $fileType = strtolower(trim((string) ($data['fileType'] ?? '')));

        $error = $this->validateManagedFileData($userId, $fileName, $filePath, $fileType);

        if ($error !== null) {
            return $this->json([
                'success' => false,
                'message' => $error,
            ], 400);
        }

        $user = $userRepository->find($userId);

        if (!$user instanceof User) {
            return $this->json([
                'success' => false,
                'message' => 'User ID does not exist.',
            ], 404);
        }

        $existingFilePath = $managedFileRepository->findOneBy([
            'filePath' => $filePath,
        ]);

        if ($existingFilePath instanceof ManagedFile) {
            return $this->json([
                'success' => false,
                'message' => 'This file path already exists.',
            ], 409);
        }

        $now = new \DateTimeImmutable();

        $file = new ManagedFile();
        $file->setUser($user);
        $file->setFileName($fileName);
        $file->setFilePath($filePath);
        $file->setFileType($fileType);

        if (method_exists($file, 'setCreatedAt')) {
            $file->setCreatedAt($now);
        }

        if (method_exists($file, 'setUpdatedAt')) {
            $file->setUpdatedAt($now);
        }

        $em->persist($file);
        $em->flush();

        return $this->json([
            'success' => true,
            'message' => 'Managed file created successfully.',
            'file' => $this->serializeManagedFile($file),
        ], 201);
    }

    #[Route('/{id}', name: 'api_managed_files_update', methods: ['PUT'])]
    public function update(
        int $id,
        Request $request,
        UserRepository $userRepository,
        ManagedFileRepository $managedFileRepository,
        EntityManagerInterface $em
    ): JsonResponse {
        $file = $managedFileRepository->find($id);

        if (!$file instanceof ManagedFile) {
            return $this->json([
                'success' => false,
                'message' => 'Managed file not found.',
            ], 404);
        }

        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json([
                'success' => false,
                'message' => 'Invalid JSON body.',
            ], 400);
        }

        $userId = (int) ($data['userId'] ?? 0);
        $fileName = trim((string) ($data['fileName'] ?? ''));
        $filePath = trim((string) ($data['filePath'] ?? ''));
        $fileType = strtolower(trim((string) ($data['fileType'] ?? '')));

        $error = $this->validateManagedFileData($userId, $fileName, $filePath, $fileType);

        if ($error !== null) {
            return $this->json([
                'success' => false,
                'message' => $error,
            ], 400);
        }

        $user = $userRepository->find($userId);

        if (!$user instanceof User) {
            return $this->json([
                'success' => false,
                'message' => 'User ID does not exist.',
            ], 404);
        }

        $existingFilePath = $managedFileRepository->findOneBy([
            'filePath' => $filePath,
        ]);

        if ($existingFilePath instanceof ManagedFile && $existingFilePath->getId() !== $file->getId()) {
            return $this->json([
                'success' => false,
                'message' => 'This file path already exists.',
            ], 409);
        }

        $file->setUser($user);
        $file->setFileName($fileName);
        $file->setFilePath($filePath);
        $file->setFileType($fileType);

        if (method_exists($file, 'setUpdatedAt')) {
            $file->setUpdatedAt(new \DateTimeImmutable());
        }

        $em->flush();

        return $this->json([
            'success' => true,
            'message' => 'Managed file updated successfully.',
            'file' => $this->serializeManagedFile($file),
        ]);
    }

    #[Route('/{id}', name: 'api_managed_files_delete', methods: ['DELETE'])]
    public function delete(
        int $id,
        ManagedFileRepository $managedFileRepository,
        EntityManagerInterface $em
    ): JsonResponse {
        $file = $managedFileRepository->find($id);

        if (!$file instanceof ManagedFile) {
            return $this->json([
                'success' => false,
                'message' => 'Managed file not found.',
            ], 404);
        }

        $em->remove($file);
        $em->flush();

        return $this->json([
            'success' => true,
            'message' => 'Managed file deleted successfully.',
        ]);
    }

    private function validateManagedFileData(int $userId, string $fileName, string $filePath, string $fileType): ?string
    {
        if ($userId <= 0) {
            return 'User ID is required.';
        }

        if (mb_strlen($fileName) < 3) {
            return 'File name must be at least 3 characters.';
        }

        if (mb_strlen($filePath) < 5) {
            return 'File path must be at least 5 characters.';
        }

        if (mb_strlen($fileType) < 2) {
            return 'File type must be at least 2 characters.';
        }

        return null;
    }

    private function serializeManagedFile(ManagedFile $file): array
    {
        return [
            'id' => $file->getId(),
            'userId' => $file->getUser()?->getId(),
            'userName' => $file->getUser()?->getFullName(),
            'fileName' => $file->getFileName(),
            'filePath' => $file->getFilePath(),
            'fileType' => $file->getFileType(),
            'createdAt' => method_exists($file, 'getCreatedAt') && $file->getCreatedAt()
                ? $file->getCreatedAt()->format('Y-m-d H:i:s')
                : '',
            'updatedAt' => method_exists($file, 'getUpdatedAt') && $file->getUpdatedAt()
                ? $file->getUpdatedAt()->format('Y-m-d H:i:s')
                : '',
        ];
    }
}