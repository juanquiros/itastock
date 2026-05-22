<?php

namespace App\Repository;

use App\Entity\Business;
use App\Entity\FiscalRule;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class FiscalRuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FiscalRule::class);
    }

    public function findActiveForBusiness(Business $business, ?\DateTimeInterface $date = null): array
    {
        $date ??= new \DateTimeImmutable('today');
        return $this->createQueryBuilder('r')
            ->andWhere('r.business = :business')->setParameter('business', $business)
            ->andWhere('r.active = true')
            ->andWhere('(r.startsAt IS NULL OR r.startsAt <= :date)')->setParameter('date', $date)
            ->andWhere('(r.endsAt IS NULL OR r.endsAt >= :date)')
            ->orderBy('r.priority', 'ASC')->addOrderBy('r.id', 'ASC')
            ->getQuery()->getResult();
    }

    public function findForAdminList(Business $business): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.business = :business')->setParameter('business', $business)
            ->orderBy('r.active', 'DESC')->addOrderBy('r.priority', 'ASC')->addOrderBy('r.name', 'ASC')
            ->getQuery()->getResult();
    }
}
