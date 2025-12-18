<?php

namespace App\Repository;

use App\Entity\Business;
use App\Entity\Payment;
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
}
