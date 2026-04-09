<?php

namespace App\Repository;

use App\Entity\Business;
use App\Entity\SupplierPayment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SupplierPayment>
 */
class SupplierPaymentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SupplierPayment::class);
    }

    /**
     * @return array<string, string>
     */
    public function aggregateTotalsByMethod(Business $business, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = $this->createQueryBuilder('sp')
            ->select('sp.paymentMethod AS method', 'SUM(sp.amount) AS total')
            ->andWhere('sp.business = :business')
            ->andWhere('sp.paidAt >= :from')
            ->andWhere('sp.paidAt <= :to')
            ->setParameter('business', $business)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->groupBy('sp.paymentMethod')
            ->getQuery()
            ->getArrayResult();

        $totals = [];
        foreach ($rows as $row) {
            $totals[(string) $row['method']] = number_format((float) $row['total'], 2, '.', '');
        }

        return $totals;
    }

    /**
     * @return list<SupplierPayment>
     */
    public function findRecentForBusiness(Business $business, int $limit = 50): array
    {
        return $this->createQueryBuilder('sp')
            ->andWhere('sp.business = :business')
            ->setParameter('business', $business)
            ->orderBy('sp.paidAt', 'DESC')
            ->addOrderBy('sp.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
