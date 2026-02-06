<?php

namespace App\Service;

use App\Entity\Business;
use App\Entity\Product;
use App\Entity\PurchaseOrder;
use App\Entity\PurchaseOrderItem;
use App\Entity\Supplier;
use Doctrine\ORM\EntityManagerInterface;

class PurchaseSuggestionService
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return array<int, PurchaseOrder>
     */
    public function createDraftOrders(Business $business): array
    {
        $productRepo = $this->entityManager->getRepository(Product::class);
        $products = $productRepo->createQueryBuilder('p')
            ->andWhere('p.business = :business')
            ->andWhere('p.stock <= p.stockMin')
            ->andWhere('p.supplier IS NOT NULL')
            ->setParameter('business', $business)
            ->getQuery()
            ->getResult();

        /** @var array<int, PurchaseOrder> $orders */
        $orders = [];

        foreach ($products as $product) {
            if (!$product instanceof Product) {
                continue;
            }

            $supplier = $product->getSupplier();
            if (!$supplier instanceof Supplier) {
                continue;
            }

            $targetStock = $product->getTargetStock() ?? $product->getStockMin();
            $suggestedQty = bcsub($targetStock, $product->getStock(), 3);
            if (bccomp($suggestedQty, '0', 3) <= 0) {
                continue;
            }

            $supplierId = $supplier->getId() ?? spl_object_id($supplier);
            if (!array_key_exists($supplierId, $orders)) {
                $order = new PurchaseOrder();
                $order->setBusiness($business);
                $order->setSupplier($supplier);
                $this->entityManager->persist($order);
                $orders[$supplierId] = $order;
            }

            $order = $orders[$supplierId];
            $item = new PurchaseOrderItem();
            $item->setProduct($product);
            $item->setQuantity($suggestedQty);
            $unitCost = $product->getPurchasePrice() ?? $product->getCost() ?? '0.00';
            $item->setUnitCost($unitCost);
            $subtotal = bcmul($suggestedQty, $unitCost, 2);
            $item->setSubtotal($subtotal);
            $order->addItem($item);
        }

        $this->entityManager->flush();

        return array_values($orders);
    }
}
