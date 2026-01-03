<?php

namespace App\Repository;

use App\Entity\Business;
use App\Entity\MercadoPagoSubscriptionLink;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MercadoPagoSubscriptionLink>
 */
class MercadoPagoSubscriptionLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MercadoPagoSubscriptionLink::class);
    }

    public function clearPrimaryForBusiness(Business $business): void
    {
        $this->createQueryBuilder('link')
            ->update()
            ->set('link.isPrimary', ':isPrimary')
            ->where('link.business = :business')
            ->setParameter('isPrimary', false)
            ->setParameter('business', $business)
            ->getQuery()
            ->execute();
    }
}
