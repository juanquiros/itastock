<?php

namespace App\Repository;

use App\Entity\PublicVisit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PublicVisit>
 */
class PublicVisitRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PublicVisit::class);
    }

    public function countVisits(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return (int) $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->andWhere('v.createdAt BETWEEN :from AND :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countUniqueIps(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return (int) $this->createQueryBuilder('v')
            ->select('COUNT(DISTINCT v.ipHash)')
            ->andWhere('v.createdAt BETWEEN :from AND :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return array<int, array{routeName: string, path: string, total: int}>
     */
    public function topPages(\DateTimeImmutable $from, \DateTimeImmutable $to, int $limit = 10): array
    {
        $rows = $this->createQueryBuilder('v')
            ->select('v.routeName AS routeName, v.path AS path, COUNT(v.id) AS total')
            ->andWhere('v.createdAt BETWEEN :from AND :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->groupBy('v.routeName, v.path')
            ->orderBy('total', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row) => [
            'routeName' => (string) $row['routeName'],
            'path' => (string) $row['path'],
            'total' => (int) $row['total'],
        ], $rows);
    }

    /**
     * @return array<int, array{referer: string, total: int}>
     */
    public function topReferrers(\DateTimeImmutable $from, \DateTimeImmutable $to, int $limit = 10): array
    {
        $rows = $this->createQueryBuilder('v')
            ->select("CASE WHEN v.referer IS NULL OR v.referer = '' THEN :direct ELSE v.referer END AS referer, COUNT(v.id) AS total")
            ->andWhere('v.createdAt BETWEEN :from AND :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('direct', 'Directo / Sin referer')
            ->groupBy('referer')
            ->orderBy('total', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row) => [
            'referer' => (string) $row['referer'],
            'total' => (int) $row['total'],
        ], $rows);
    }

    /**
     * @return array<int, array{utmSource: string, total: int}>
     */
    public function topUtmSources(\DateTimeImmutable $from, \DateTimeImmutable $to, int $limit = 10): array
    {
        $rows = $this->createQueryBuilder('v')
            ->select("CASE WHEN v.utmSource IS NULL OR v.utmSource = '' THEN :empty ELSE v.utmSource END AS utmSource, COUNT(v.id) AS total")
            ->andWhere('v.createdAt BETWEEN :from AND :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('empty', 'Sin UTM')
            ->groupBy('utmSource')
            ->orderBy('total', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row) => [
            'utmSource' => (string) $row['utmSource'],
            'total' => (int) $row['total'],
        ], $rows);
    }

    /**
     * @return array<int, PublicVisit>
     */
    public function latestVisits(\DateTimeImmutable $from, \DateTimeImmutable $to, int $limit = 200): array
    {
        return $this->createQueryBuilder('v')
            ->andWhere('v.createdAt BETWEEN :from AND :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('v.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
