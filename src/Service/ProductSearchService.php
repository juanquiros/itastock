<?php

namespace App\Service;

use App\Entity\Product;
use App\Repository\ProductRepository;
use App\Security\BusinessContext;

class ProductSearchService
{
    public function __construct(
        private readonly ProductRepository $productRepository,
        private readonly BusinessContext $businessContext,
        private readonly ProductSearchTextBuilder $searchTextBuilder,
    ) {
    }

    /**
     * @return Product[]
     */
    public function search(string $query, int $limit = 50): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $business = $this->businessContext->getCurrentBusiness();
        if ($business === null) {
            return [];
        }

        $barcodeMatch = $this->productRepository->findOneByBusinessAndExactBarcode($business, $query);
        if ($barcodeMatch instanceof Product) {
            return [$barcodeMatch];
        }

        $skuMatch = $this->productRepository->findOneByBusinessAndExactSku($business, $query);
        if ($skuMatch instanceof Product) {
            return [$skuMatch];
        }

        $nameMatches = $this->productRepository->findByBusinessAndNameLike($business, $query, $limit);
        if ($nameMatches !== []) {
            return $nameMatches;
        }

        $tokens = $this->searchTextBuilder->tokenize($query);
        if ($tokens === []) {
            return [];
        }

        return $this->productRepository->findByBusinessAndSearchTokens($business, $tokens, $limit);
    }
}
