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

    /**
     * @return string[]
     */
    public function findSkusForBusinessPrefix(Business $business, string $prefix): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('p.sku AS sku')
            ->andWhere('p.business = :business')
            ->andWhere('p.sku LIKE :prefix')
            ->setParameter('business', $business)
            ->setParameter('prefix', $prefix.'%')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row) => (string) $row['sku'], $rows);
    }

    /**
     * @param int[] $categoryIds
     * @param int[] $brandIds
     *
     * @return Product[]
     */
    public function findForLabelFilters(Business $business, array $categoryIds, array $brandIds, ?\DateTimeImmutable $updatedSince): array
    {
        $qb = $this->createQueryBuilder('p')
            ->andWhere('p.business = :business')
            ->setParameter('business', $business)
            ->orderBy('p.name', 'ASC');

        if ($categoryIds !== []) {
            $qb->andWhere('p.category IN (:categories)')
                ->setParameter('categories', $categoryIds);
        }

        if ($brandIds !== []) {
            $qb->andWhere('p.brand IN (:brands)')
                ->setParameter('brands', $brandIds);
        }

        if ($updatedSince !== null) {
            $qb->andWhere('p.updatedAt >= :updatedSince')
                ->setParameter('updatedSince', $updatedSince);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return array<int, array{productId: int, sku: string, name: string, stock: float, min: float}>
     */
    public function findLowStockTop(Business $business, int $limit = 5): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('p.id AS productId')
            ->addSelect('p.sku AS sku')
            ->addSelect('p.name AS name')
            ->addSelect('p.stock AS stock')
            ->addSelect('p.stockMin AS min')
            ->addSelect('p.stock - p.stockMin AS HIDDEN deficit')
            ->andWhere('p.business = :business')
            ->andWhere('p.stock <= p.stockMin')
            ->setParameter('business', $business)
            ->orderBy('deficit', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row) => [
            'productId' => (int) $row['productId'],
            'sku' => (string) $row['sku'],
            'name' => (string) $row['name'],
            'stock' => (float) $row['stock'],
            'min' => (float) $row['min'],
        ], $rows);
    }
}
