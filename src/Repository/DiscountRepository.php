<?php

namespace App\Repository;

use App\Entity\Business;
use App\Entity\Discount;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Discount>
 */
class DiscountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Discount::class);
    }

    /**
     * @return array<int, Discount>
     */
    public function findActiveForBusiness(Business $business, \DateTimeImmutable $now): array
    {
        $qb = $this->createQueryBuilder('d')
            ->andWhere('d.business = :business')
            ->andWhere('d.status = :status')
            ->andWhere('(d.startAt IS NULL OR d.startAt <= :now)')
            ->andWhere('(d.endAt IS NULL OR d.endAt >= :now)')
            ->setParameter('business', $business)
            ->setParameter('status', Discount::STATUS_ACTIVE)
            ->setParameter('now', $now)
            ->orderBy('d.priority', 'DESC')
            ->addOrderBy('d.id', 'ASC');

        return $qb->getQuery()->getResult();
    }
}
