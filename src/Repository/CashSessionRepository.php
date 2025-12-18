<?php

namespace App\Repository;

use App\Entity\Business;
use App\Entity\CashSession;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CashSession>
 */
class CashSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CashSession::class);
    }

    public function findOpenForBusiness(Business $business): ?CashSession
    {
        return $this->createQueryBuilder('cs')
            ->andWhere('cs.business = :business')
            ->andWhere('cs.closedAt IS NULL')
            ->setParameter('business', $business)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
