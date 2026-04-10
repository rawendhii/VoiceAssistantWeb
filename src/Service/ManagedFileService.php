<?php

namespace App\Service;

use App\Entity\ManagedFile;
use App\Entity\User;
use App\Repository\ManagedFileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class ManagedFileService
{
    public function __construct(
        private EntityManagerInterface $em,
        private ManagedFileRepository $managedFileRepository
    ) {
    }

    public function validateFileData(string $fileName, string $filePath, string $fileType): ?string
    {
        if ($fileName === '' || $filePath === '' || $fileType === '') {
            return 'All fields are required.';
        }

        if (mb_strlen($fileName) < 2) {
            return 'File name must be at least 2 characters.';
        }

        if (mb_strlen($fileType) < 2) {
            return 'File type must be at least 2 characters.';
        }

        return null;
    }

    public function createForUser(User $user, string $fileName, string $filePath, string $fileType): ManagedFile
    {
        $file = new ManagedFile();
        $file->setUser($user);
        $file->setFileName(trim($fileName));
        $file->setFilePath(trim($filePath));
        $file->setFileType(strtoupper(trim($fileType)));
        $file->setCreatedAt(new \DateTimeImmutable());
        $file->setUpdatedAt(new \DateTimeImmutable());

        $this->em->persist($file);
        $this->em->flush();

        return $file;
    }

    public function updateFile(ManagedFile $file, string $fileName, string $filePath, string $fileType): ManagedFile
    {
        $file->setFileName(trim($fileName));
        $file->setFilePath(trim($filePath));
        $file->setFileType(strtoupper(trim($fileType)));
        $file->setUpdatedAt(new \DateTimeImmutable());

        $this->em->flush();

        return $file;
    }

    public function deleteFile(ManagedFile $file): void
    {
        $this->em->remove($file);
        $this->em->flush();
    }

    public function assertOwnership(ManagedFile $file, User $user): void
    {
        if ($file->getUser()?->getId() !== $user->getId()) {
            throw new AccessDeniedHttpException('You cannot access this file.');
        }
    }

    public function getPaginatedUserFiles(User $user, string $search, string $typeFilter, int $page, int $limit = 5): array
    {
        $page = max(1, $page);
        $offset = ($page - 1) * $limit;

        $qb = $this->managedFileRepository->createQueryBuilder('mf')
            ->andWhere('mf.user = :user')
            ->setParameter('user', $user);

        if ($search !== '') {
            $qb->andWhere('UPPER(mf.fileName) LIKE UPPER(:search) OR UPPER(mf.filePath) LIKE UPPER(:search) OR UPPER(mf.fileType) LIKE UPPER(:search)')
                ->setParameter('search', '%' . $search . '%');
        }

        if ($typeFilter !== '') {
            $qb->andWhere('UPPER(mf.fileType) = UPPER(:type)')
                ->setParameter('type', $typeFilter);
        }

        $total = (int) (clone $qb)
            ->select('COUNT(mf.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $files = $qb
            ->orderBy('mf.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return [
            'files' => $files,
            'page' => $page,
            'totalPages' => max(1, (int) ceil($total / $limit)),
            'total' => $total,
        ];
    }
}