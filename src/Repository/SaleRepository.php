<?php

namespace App\Repository;

use App\Entity\Business;
use App\Entity\Sale;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Sale>
 */
class SaleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Sale::class);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findForExport(Business $business, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $qb = $this->createQueryBuilder('s')
            ->select('s.id AS saleId')
            ->addSelect('s.createdAt AS createdAt')
            ->addSelect('s.total AS total')
            ->addSelect('u.email AS sellerEmail')
            ->addSelect('COALESCE(c.name, :defaultCustomer) AS customerName')
            ->addSelect('MIN(p.method) AS paymentMethod')
            ->addSelect('COUNT(DISTINCT items.id) AS itemsCount')
            ->leftJoin('s.createdBy', 'u')
            ->leftJoin('s.customer', 'c')
            ->leftJoin('s.items', 'items')
            ->leftJoin('s.payments', 'p')
            ->andWhere('s.business = :business')
            ->andWhere('s.createdAt >= :from')
            ->andWhere('s.createdAt <= :to')
            ->groupBy('s.id, u.email, c.name, s.total, s.createdAt')
            ->orderBy('s.createdAt', 'ASC')
            ->setParameter('business', $business)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('defaultCustomer', 'Consumidor final');

        return $qb->getQuery()->getArrayResult();
    }

    /**
     * @return array{amount: float, count: int, avg: float}
     */
    public function aggregateTotals(Business $business, \DateTimeImmutable $from, \DateTimeImmutable $to, ?User $seller = null): array
    {
        $qb = $this->createQueryBuilder('s')
            ->select('COALESCE(SUM(s.total), 0) AS amount')
            ->addSelect('COUNT(s.id) AS count')
            ->addSelect('COALESCE(AVG(s.total), 0) AS avg')
            ->andWhere('s.business = :business')
            ->andWhere('s.createdAt >= :from')
            ->andWhere('s.createdAt < :to')
            ->setParameter('business', $business)
            ->setParameter('from', $from)
            ->setParameter('to', $to);

        if ($seller !== null) {
            $qb->andWhere('s.createdBy = :seller')->setParameter('seller', $seller);
        }

        $result = $qb->getQuery()->getSingleResult();

        return [
            'amount' => (float) $result['amount'],
            'count' => (int) $result['count'],
            'avg' => (float) $result['avg'],
        ];
    }

    /**
     * @return array<int, array{hour: int, amount: float}>
     */
    public function aggregateByHour(Business $business, \DateTimeImmutable $from, \DateTimeImmutable $to, ?User $seller = null): array
    {
        $qb = $this->createQueryBuilder('s')
            ->select("FUNCTION('HOUR', s.createdAt) AS hour")
            ->addSelect('COALESCE(SUM(s.total), 0) AS amount')
            ->andWhere('s.business = :business')
            ->andWhere('s.createdAt >= :from')
            ->andWhere('s.createdAt < :to')
            ->setParameter('business', $business)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->groupBy('hour')
            ->orderBy('hour', 'ASC');

        if ($seller !== null) {
            $qb->andWhere('s.createdBy = :seller')->setParameter('seller', $seller);
        }

        return array_map(static fn (array $row) => [
            'hour' => (int) $row['hour'],
            'amount' => (float) $row['amount'],
        ], $qb->getQuery()->getArrayResult());
    }

    /**
     * @return array<int, array{date: string, amount: float}>
     */
    public function aggregateByDate(Business $business, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $qb = $this->createQueryBuilder('s')
            ->select("FUNCTION('DATE_FORMAT', s.createdAt, '%Y-%m-%d') AS date")
            ->addSelect('COALESCE(SUM(s.total), 0) AS amount')
            ->andWhere('s.business = :business')
            ->andWhere('s.createdAt >= :from')
            ->andWhere('s.createdAt < :to')
            ->setParameter('business', $business)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->groupBy('date')
            ->orderBy('date', 'ASC');

        return array_map(static fn (array $row) => [
            'date' => (string) $row['date'],
            'amount' => (float) $row['amount'],
        ], $qb->getQuery()->getArrayResult());
    }

    public function countActiveCustomers(Business $business, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $qb = $this->createQueryBuilder('s')
            ->select('COUNT(DISTINCT c.id)')
            ->leftJoin('s.customer', 'c')
            ->andWhere('s.business = :business')
            ->andWhere('s.createdAt >= :from')
            ->andWhere('s.createdAt < :to')
            ->andWhere('c IS NOT NULL')
            ->andWhere('c.isActive = true')
            ->setParameter('business', $business)
            ->setParameter('from', $from)
            ->setParameter('to', $to);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findRecentSales(Business $business, int $limit = 10): array
    {
        return $this->createQueryBuilder('s')
            ->select('s.id AS saleId')
            ->addSelect('s.createdAt AS createdAt')
            ->addSelect('s.total AS total')
            ->addSelect('COALESCE(c.name, :defaultCustomer) AS customerName')
            ->addSelect('MIN(p.method) AS paymentMethod')
            ->leftJoin('s.customer', 'c')
            ->leftJoin('s.payments', 'p')
            ->andWhere('s.business = :business')
            ->setParameter('business', $business)
            ->setParameter('defaultCustomer', 'Consumidor final')
            ->groupBy('s.id, c.name, s.createdAt, s.total')
            ->orderBy('s.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();
    }
}
