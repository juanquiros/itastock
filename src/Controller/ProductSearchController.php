<?php

namespace App\Controller;

use App\Service\ProductSearchService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('BUSINESS_SELLER')]
class ProductSearchController extends AbstractController
{
    #[Route('/products/search', name: 'app_products_search', methods: ['GET'])]
    public function __invoke(Request $request, ProductSearchService $searchService): JsonResponse
    {
        $query = (string) $request->query->get('q', '');
        $limit = min(50, max(1, (int) $request->query->get('limit', 50)));

        $products = $searchService->search($query, $limit);

        return $this->json(array_map(static fn ($product) => [
            'id' => $product->getId(),
            'name' => $product->getName(),
            'sku' => $product->getSku(),
            'barcode' => $product->getBarcode(),
            'precio' => (float) $product->getBasePrice(),
            'stock' => (float) $product->getStock(),
            'uomBase' => $product->getUomBase(),
            'allowsFractionalQty' => $product->allowsFractionalQty(),
            'qtyStep' => $product->getQtyStep(),
            'characteristicsSummary' => $product->getCharacteristicsSummary(),
        ], $products));
    }
}
