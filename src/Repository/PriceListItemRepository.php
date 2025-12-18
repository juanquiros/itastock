<?php

namespace App\Repository;

use App\Entity\Business;
use App\Entity\PriceList;
use App\Entity\PriceListItem;
use App\Entity\Product;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PriceListItem>
 */
class PriceListItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PriceListItem::class);
    }

    /**
     * @param Product[] $products
     *
     * @return array<int, float> keyed by product id
     */
    public function findPricesForListAndProducts(PriceList $priceList, array $products): array
    {
        if (count($products) === 0) {
            return [];
        }

        $productIds = array_map(static fn (Product $product) => $product->getId(), $products);

        $rows = $this->createQueryBuilder('pli')
            ->select('IDENTITY(pli.product) AS productId, pli.price AS price')
            ->andWhere('pli.priceList = :list')
            ->andWhere('pli.product IN (:products)')
            ->setParameter('list', $priceList)
            ->setParameter('products', $productIds)
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['productId']] = (float) $row['price'];
        }

        return $result;
    }

    /**
     * @return array<int, array<int, float>> keyed by price list id then product id
     */
    public function findPricesByBusiness(Business $business): array
    {
        $rows = $this->createQueryBuilder('pli')
            ->select('IDENTITY(pli.priceList) AS listId, IDENTITY(pli.product) AS productId, pli.price AS price')
            ->andWhere('pli.business = :business')
            ->setParameter('business', $business)
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            $listId = (int) $row['listId'];
            $productId = (int) $row['productId'];
            $result[$listId][$productId] = (float) $row['price'];
        }

        return $result;
    }
}
