<?php

namespace App\Service;

use App\Entity\Business;
use App\Entity\Customer;
use App\Entity\PriceList;
use App\Entity\Product;
use App\Repository\PriceListItemRepository;
use App\Repository\PriceListRepository;

class PricingService
{
    public function __construct(
        private readonly PriceListRepository $priceListRepository,
        private readonly PriceListItemRepository $priceListItemRepository,
    ) {
    }

    public function resolveUnitPrice(Product $product, ?Customer $customer): float
    {
        $business = $product->getBusiness();
        $priceList = $this->resolvePriceList($business, $customer);

        if ($priceList instanceof PriceList) {
            $item = $this->priceListItemRepository->findOneBy([
                'priceList' => $priceList,
                'product' => $product,
            ]);

            if ($item !== null) {
                return (float) $item->getPrice();
            }
        }

        return (float) $product->getBasePrice();
    }

    private function resolvePriceList(Business $business, ?Customer $customer): ?PriceList
    {
        if ($customer !== null && $customer->getPriceList() !== null) {
            $list = $customer->getPriceList();
            if ($list->isActive() && $list->getBusiness() === $business) {
                return $list;
            }
        }

        return $this->priceListRepository->findDefaultActiveForBusiness($business);
    }
}
