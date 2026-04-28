<?php

namespace App\Repository;

use App\Entity\CommandHistory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CommandHistory>
 */
class CommandHistoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CommandHistory::class);
    }
    public function countByStatus(string $status): int
{
    return (int) $this->createQueryBuilder('h')
        ->select('COUNT(h.id)')
        ->andWhere('h.status = :status')
        ->setParameter('status', $status)
        ->getQuery()
        ->getSingleScalarResult();
}

public function findLatest(int $limit = 5): array
{
    return $this->createQueryBuilder('h')
        ->leftJoin('h.user', 'u')
        ->addSelect('u')
        ->leftJoin('h.command', 'c')
        ->addSelect('c')
        ->orderBy('h.executedAt', 'DESC')
        ->setMaxResults($limit)
        ->getQuery()
        ->getResult();
}

public function findMostUsedCommands(int $limit = 5): array
{
    return $this->createQueryBuilder('h')
        ->select('c.keyword AS keyword, c.label AS label, COUNT(h.id) AS total')
        ->join('h.command', 'c')
        ->groupBy('c.id')
        ->orderBy('total', 'DESC')
        ->setMaxResults($limit)
        ->getQuery()
        ->getArrayResult();
}

    //    /**
    //     * @return CommandHistory[] Returns an array of CommandHistory objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('c')
    //            ->andWhere('c.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('c.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?CommandHistory
    //    {
    //        return $this->createQueryBuilder('c')
    //            ->andWhere('c.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
