<?php

namespace App\Service;

use App\Entity\Business;
use App\Repository\ProductRepository;

class SkuGenerator
{
    public function __construct(private readonly ProductRepository $productRepository)
    {
    }

    public function generateNextSkuForBusiness(Business $business): string
    {
        $prefix = sprintf('K%d-', $business->getId());
        $skus = $this->productRepository->findSkusForBusinessPrefix($business, $prefix);
        $max = 0;

        foreach ($skus as $sku) {
            if (preg_match('/^'.preg_quote($prefix, '/').'(\d+)$/', $sku, $matches) !== 1) {
                continue;
            }

            $value = (int) $matches[1];
            if ($value > $max) {
                $max = $value;
            }
        }

        return sprintf('%s%05d', $prefix, $max + 1);
    }
}
