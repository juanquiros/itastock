<?php

namespace App\Repository;

use App\Entity\Business;
use App\Entity\FiscalRuleAuditLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class FiscalRuleAuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, FiscalRuleAuditLog::class); }
    public function findForAudit(Business $business, ?\DateTimeInterface $from = null, ?\DateTimeInterface $to = null, ?string $action = null): array
    {
        $qb = $this->createQueryBuilder('l')->andWhere('l.business=:b')->setParameter('b', $business)->orderBy('l.createdAt', 'DESC');
        if ($from) $qb->andWhere('l.createdAt >= :from')->setParameter('from', $from);
        if ($to) $qb->andWhere('l.createdAt <= :to')->setParameter('to', $to);
        if ($action) $qb->andWhere('l.action = :a')->setParameter('a', $action);
        return $qb->getQuery()->getResult();
    }
}
