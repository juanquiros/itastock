<?php

namespace App\Repository;

use App\Entity\Business;
use App\Entity\Customer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Customer>
 */
class CustomerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Customer::class);
    }

    /**
     * @return Customer[]
     */
    public function searchByBusiness(Business $business, ?string $term): array
    {
        $qb = $this->createQueryBuilder('c')
            ->andWhere('c.business = :business')
            ->setParameter('business', $business)
            ->orderBy('LOWER(c.name)', 'ASC');

        if ($term !== null && trim($term) !== '') {
            $like = '%'.mb_strtolower(trim($term)).'%';
            $qb->andWhere('LOWER(c.name) LIKE :term OR c.documentNumber LIKE :term OR LOWER(c.phone) LIKE :term')
                ->setParameter('term', $like);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return Customer[]
     */
    public function findActiveForBusiness(Business $business): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.business = :business')
            ->andWhere('c.isActive = true')
            ->setParameter('business', $business)
            ->orderBy('LOWER(c.name)', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @param int[] $ids
     *
     * @return Customer[]
     */
    public function findByIdsForBusiness(Business $business, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->createQueryBuilder('c')
            ->andWhere('c.business = :business')
            ->andWhere('c.id IN (:ids)')
            ->setParameter('business', $business)
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
    }
}
