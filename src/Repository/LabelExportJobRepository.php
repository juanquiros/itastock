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

    /**
     * @return array{items: array<int, LabelExportJob>, total: int, page: int, pages: int, limit: int}
     */
    public function findPaginatedForBusiness(Business $business, int $page, int $limit): array
    {
        $page = max(1, $page);
        $limit = max(1, min(10, $limit));

        $qb = $this->createQueryBuilder('j')
            ->andWhere('j.business = :business')
            ->setParameter('business', $business)
            ->orderBy('j.createdAt', 'DESC');

        $total = (int) (clone $qb)
            ->select('COUNT(j.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $pages = max(1, (int) ceil($total / $limit));
        $page = min($page, $pages);

        $items = $qb
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'limit' => $limit,
        ];
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
