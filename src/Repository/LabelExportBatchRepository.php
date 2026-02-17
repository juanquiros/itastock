<?php

namespace App\Repository;

use App\Entity\LabelExportBatch;
use App\Entity\LabelExportJob;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LabelExportBatch>
 */
class LabelExportBatchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LabelExportBatch::class);
    }

    /** @return LabelExportBatch[] */
    public function findForJob(LabelExportJob $job): array
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.job = :job')
            ->setParameter('job', $job)
            ->orderBy('b.batchIndex', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
