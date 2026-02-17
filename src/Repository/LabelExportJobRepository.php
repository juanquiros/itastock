<?php

namespace App\Repository;

use App\Entity\Business;
use App\Entity\LabelExportJob;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LabelExportJob>
 */
class LabelExportJobRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LabelExportJob::class);
    }

    /** @return LabelExportJob[] */
    public function findRecentForBusiness(Business $business, int $limit = 20): array
    {
        return $this->createQueryBuilder('j')
            ->andWhere('j.business = :business')
            ->setParameter('business', $business)
            ->orderBy('j.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @return LabelExportJob[] */
    public function findExpiredActiveJobs(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('j')
            ->andWhere('j.expiresAt <= :now')
            ->andWhere('j.status IN (:statuses)')
            ->setParameter('now', $now)
            ->setParameter('statuses', [
                LabelExportJob::STATUS_QUEUED,
                LabelExportJob::STATUS_RUNNING,
                LabelExportJob::STATUS_READY,
                LabelExportJob::STATUS_FAILED,
            ])
            ->getQuery()
            ->getResult();
    }
}
