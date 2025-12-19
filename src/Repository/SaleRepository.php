<?php

namespace App\Repository;

use App\Entity\Business;
use App\Entity\Sale;
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
}
