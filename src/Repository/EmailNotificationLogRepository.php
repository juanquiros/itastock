<?php

namespace App\Repository;

use App\Entity\EmailNotificationLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EmailNotificationLog>
 */
class EmailNotificationLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmailNotificationLog::class);
    }

    /**
     * @return array{items: array<int, EmailNotificationLog>, total: int}
     */
    public function findPlatformLogs(string $query, int $page, int $limit): array
    {
        $qb = $this->createQueryBuilder('e')
            ->orderBy('e.createdAt', 'DESC');

        if ($query !== '') {
            $qb
                ->andWhere('e.recipientEmail LIKE :q OR e.type LIKE :q')
                ->setParameter('q', '%'.$query.'%');
        }

        $total = (int) (clone $qb)
            ->select('COUNT(e.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $items = $qb
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return [
            'items' => $items,
            'total' => $total,
        ];
    }
}
