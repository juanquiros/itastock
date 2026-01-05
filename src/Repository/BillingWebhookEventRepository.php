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

    public function findProcessedByEventId(?string $eventId): ?BillingWebhookEvent
    {
        if ($eventId === null) {
            return null;
        }

        return $this->createQueryBuilder('e')
            ->andWhere('e.processedAt IS NOT NULL')
            ->andWhere('e.eventId = :eventId')
            ->setParameter('eventId', $eventId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findRecentByResource(string $resourceId, \DateTimeImmutable $since): ?BillingWebhookEvent
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.resourceId = :resourceId')
            ->andWhere('e.receivedAt >= :since')
            ->setParameter('resourceId', $resourceId)
            ->setParameter('since', $since)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
