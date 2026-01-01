<?php

namespace App\Repository;

use App\Entity\PendingSubscriptionChange;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PendingSubscriptionChange>
 */
class PendingSubscriptionChangeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PendingSubscriptionChange::class);
    }
}
