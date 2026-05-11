<?php

namespace App\Repository;

use App\Entity\FaceLoginProfile;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FaceLoginProfile>
 */
class FaceLoginProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FaceLoginProfile::class);
    }

    public function findOneForUser(User $user): ?FaceLoginProfile
    {
        return $this->findOneBy([
            'user' => $user,
        ]);
    }

    public function findOneEnabledForUser(User $user): ?FaceLoginProfile
    {
        return $this->findOneBy([
            'user' => $user,
            'isEnabled' => true,
        ]);
    }

    /**
     * @return FaceLoginProfile[]
     */
    public function findEnabledProfiles(): array
    {
        return $this->createQueryBuilder('f')
            ->join('f.user', 'u')
            ->addSelect('u')
            ->andWhere('f.isEnabled = :enabled')
            ->setParameter('enabled', true)
            ->getQuery()
            ->getResult();
    }
}