<?php

namespace App\Repository;

use App\Entity\ArcaTokenCache;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ArcaTokenCache>
 */
class ArcaTokenCacheRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ArcaTokenCache::class);
    }
}
