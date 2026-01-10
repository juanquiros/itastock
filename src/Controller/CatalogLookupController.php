<?php

namespace App\Controller;

use App\Entity\CatalogProduct;
use App\Repository\CatalogProductRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\Security;

#[Security("is_granted('ROLE_PLATFORM_ADMIN') or is_granted('ROLE_ADMIN') or is_granted('ROLE_BUSINESS_ADMIN')")]
class CatalogLookupController extends AbstractController
{
    #[Route('/app/catalog/lookup/barcode', name: 'app_catalog_lookup_barcode', methods: ['GET'])]
    public function barcode(Request $request, CatalogProductRepository $catalogProductRepository): JsonResponse
    {
        $barcode = trim((string) $request->query->get('barcode', ''));

        if ($barcode === '') {
            return $this->json(['found' => false]);
        }

        $product = $catalogProductRepository->findOneBy([
            'barcode' => $barcode,
            'isActive' => true,
        ]);

        if (!$product instanceof CatalogProduct) {
            return $this->json(['found' => false]);
        }

        return $this->json([
            'found' => true,
            'product' => $this->serializeCatalogProduct($product),
        ]);
    }

    #[Route('/app/catalog/lookup/name', name: 'app_catalog_lookup_name', methods: ['GET'])]
    public function name(Request $request, CatalogProductRepository $catalogProductRepository): JsonResponse
    {
        $query = trim((string) $request->query->get('q', ''));
        $limit = (int) $request->query->get('limit', 10);
        $limit = $limit > 0 ? min($limit, 25) : 10;

        if ($query === '') {
            return $this->json([]);
        }

        $qb = $catalogProductRepository->createQueryBuilder('p')
            ->leftJoin('p.brand', 'b')
            ->addSelect('b')
            ->leftJoin('p.category', 'c')
            ->addSelect('c')
            ->andWhere('p.isActive = :active')
            ->andWhere('LOWER(p.name) LIKE :query OR LOWER(b.name) LIKE :query')
            ->setParameter('active', true)
            ->setParameter('query', '%'.mb_strtolower($query).'%')
            ->orderBy('p.name', 'ASC')
            ->setMaxResults($limit);

        $results = array_map(
            fn (CatalogProduct $product) => $this->serializeCatalogProduct($product, true),
            $qb->getQuery()->getResult()
        );

        return $this->json($results);
    }

    private function serializeCatalogProduct(CatalogProduct $product, bool $includeLabel = false): array
    {
        $brand = $product->getBrand();
        $category = $product->getCategory();

        $payload = [
            'id' => $product->getId(),
            'name' => $product->getName(),
            'presentation' => $product->getPresentation(),
            'barcode' => $product->getBarcode(),
            'category' => $category ? ['id' => $category->getId(), 'name' => $category->getName()] : null,
            'brand' => $brand ? [
                'id' => $brand->getId(),
                'name' => $brand->getName(),
                'logoPath' => $brand->getLogoPath(),
            ] : null,
        ];

        if ($includeLabel) {
            $labelParts = [$product->getName()];
            if ($brand?->getName()) {
                $labelParts[] = $brand->getName();
            }
            if ($product->getPresentation()) {
                $labelParts[] = $product->getPresentation();
            }
            $payload['label'] = implode(' - ', array_filter($labelParts));
        }

        return $payload;
    }
}
