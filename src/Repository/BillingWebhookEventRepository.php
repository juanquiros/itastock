<?php

namespace App\Repository;

use App\Entity\BillingWebhookEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BillingWebhookEvent>
 */
class BillingWebhookEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BillingWebhookEvent::class);
    }

    public function findProcessedByEventOrResource(?string $eventId, ?string $resourceId): ?BillingWebhookEvent
    {
        if ($eventId === null && $resourceId === null) {
            return null;
        }

        $qb = $this->createQueryBuilder('e')
            ->andWhere('e.processedAt IS NOT NULL');

        if ($eventId !== null && $resourceId !== null) {
            $qb->andWhere('e.eventId = :eventId OR e.resourceId = :resourceId')
                ->setParameter('eventId', $eventId)
                ->setParameter('resourceId', $resourceId);
        } elseif ($eventId !== null) {
            $qb->andWhere('e.eventId = :eventId')
                ->setParameter('eventId', $eventId);
        } else {
            $qb->andWhere('e.resourceId = :resourceId')
                ->setParameter('resourceId', $resourceId);
        }

        return $qb->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }
}
