<?php

namespace App\Repository;

use App\Entity\VoiceCommand;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VoiceCommand>
 */
class VoiceCommandRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VoiceCommand::class);
    }

    //    /**
    //     * @return VoiceCommand[] Returns an array of VoiceCommand objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('v')
    //            ->andWhere('v.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('v.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?VoiceCommand
    //    {
    //        return $this->createQueryBuilder('v')
    //            ->andWhere('v.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
