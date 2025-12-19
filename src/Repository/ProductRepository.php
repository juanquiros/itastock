<?php

namespace App\Repository;

use App\Entity\Business;
use App\Entity\Product;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Product>
 */
class ProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    public function findOneByBusinessAndSku(Business $business, string $sku): ?Product
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.business = :business')
            ->andWhere('p.sku = :sku')
            ->setParameter('business', $business)
            ->setParameter('sku', $sku)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByBusinessAndBarcode(Business $business, string $barcode): ?Product
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.business = :business')
            ->andWhere('p.barcode = :barcode')
            ->setParameter('business', $business)
            ->setParameter('barcode', $barcode)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
