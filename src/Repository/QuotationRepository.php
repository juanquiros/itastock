<?php

namespace App\Repository;

use App\Entity\Business;
use App\Entity\Quotation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Quotation>
 */
class QuotationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Quotation::class);
    }

    public function nextCommercialSequence(Business $business): int
    {
        $maxId = $this->createQueryBuilder('q')
            ->select('MAX(q.id)')
            ->andWhere('q.business = :business')
            ->setParameter('business', $business)
            ->getQuery()
            ->getSingleScalarResult();

        return ((int) $maxId) + 1;
    }
}
