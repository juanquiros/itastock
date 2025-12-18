<?php

namespace App\Repository;

use App\Entity\Business;
use App\Entity\PriceList;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PriceList>
 */
class PriceListRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PriceList::class);
    }

    /**
     * @return PriceList[]
     */
    public function findActiveForBusiness(Business $business): array
    {
        return $this->createQueryBuilder('pl')
            ->andWhere('pl.business = :business')
            ->andWhere('pl.isActive = true')
            ->setParameter('business', $business)
            ->orderBy('LOWER(pl.name)', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findDefaultActiveForBusiness(Business $business): ?PriceList
    {
        return $this->createQueryBuilder('pl')
            ->andWhere('pl.business = :business')
            ->andWhere('pl.isActive = true')
            ->andWhere('pl.isDefault = true')
            ->setParameter('business', $business)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function clearDefaultForBusiness(Business $business): void
    {
        $this->createQueryBuilder('pl')
            ->update()
            ->set('pl.isDefault', ':false')
            ->andWhere('pl.business = :business')
            ->setParameter('false', false)
            ->setParameter('business', $business)
            ->getQuery()
            ->execute();
    }
}
