<?php

namespace App\Repository;

use App\Entity\Business;
use App\Entity\Payment;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Payment>
 */
class PaymentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Payment::class);
    }

    /**
     * @return array<string, string>
     */
    public function aggregateTotalsByMethod(Business $business, \DateTimeImmutable $from, ?\DateTimeImmutable $to = null): array
    {
        $qb = $this->createQueryBuilder('p')
            ->select('p.method AS method', 'SUM(p.amount) AS total')
            ->join('p.sale', 's')
            ->where('s.business = :business')
            ->andWhere('p.createdAt >= :from')
            ->setParameter('business', $business)
            ->setParameter('from', $from);

        if ($to !== null) {
            $qb->andWhere('p.createdAt <= :to')
                ->setParameter('to', $to);
        }

        $rows = $qb->groupBy('p.method')
            ->getQuery()
            ->getArrayResult();

        $totals = [];
        foreach ($rows as $row) {
            $totals[$row['method']] = number_format((float) $row['total'], 2, '.', '');
        }

        return $totals;
    }

    /**
     * @return array<string, float>
     */
    public function aggregateTotalsByMethodForRange(Business $business, \DateTimeImmutable $from, \DateTimeImmutable $to, ?User $seller = null): array
    {
        $qb = $this->createQueryBuilder('p')
            ->select('p.method AS method', 'COALESCE(SUM(p.amount), 0) AS total')
            ->join('p.sale', 's')
            ->where('s.business = :business')
            ->andWhere('p.createdAt >= :from')
            ->andWhere('p.createdAt < :to')
            ->setParameter('business', $business)
            ->setParameter('from', $from)
            ->setParameter('to', $to);

        if ($seller !== null) {
            $qb->andWhere('s.createdBy = :seller')->setParameter('seller', $seller);
        }

        $rows = $qb->groupBy('p.method')
            ->getQuery()
            ->getArrayResult();

        $totals = [];
        foreach ($rows as $row) {
            $totals[(string) $row['method']] = (float) $row['total'];
        }

        return $totals;
    }
}
