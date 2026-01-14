<?php

namespace App\Controller;

use App\Entity\Business;
use App\Entity\Product;
use App\Form\ProductLabelFilterType;
use App\Repository\ProductRepository;
use App\Security\BusinessContext;
use App\Service\BarcodeGeneratorService;
use App\Service\PdfService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('BUSINESS_ADMIN')]
class ProductLabelController extends AbstractController
{
    public function __construct(private readonly BusinessContext $businessContext)
    {
    }

    #[Route('/app/admin/products/labels', name: 'app_product_labels', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $business = $this->requireBusinessContext();

        $form = $this->createForm(ProductLabelFilterType::class, [
            'includeBarcode' => true,
            'barcodeSource' => 'ean',
            'showPrice' => true,
            'showOnlyName' => false,
            'labelsPerProduct' => 1,
        ], [
            'current_business' => $business,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $productIds = $this->extractIds($data['products'] ?? []);
            $categoryIds = $this->extractIds($data['categories'] ?? []);
            $brandIds = $this->extractIds($data['brands'] ?? []);
            $labelsPerProduct = max(1, (int) ($data['labelsPerProduct'] ?? 1));

            $params = array_filter([
                'products' => $this->serializeIds($productIds),
                'categories' => $this->serializeIds($categoryIds),
                'brands' => $this->serializeIds($brandIds),
                'updatedSince' => $data['updatedSince']?->format('Y-m-d'),
                'includeBarcode' => !empty($data['includeBarcode']) ? '1' : '0',
                'barcodeSource' => $data['barcodeSource'] ?: 'ean',
                'showPrice' => !empty($data['showPrice']) ? '1' : '0',
                'showOnlyName' => !empty($data['showOnlyName']) ? '1' : '0',
                'labelsPerProduct' => (string) $labelsPerProduct,
            ], static fn ($value) => $value !== null && $value !== '');

            return $this->redirectToRoute('app_product_labels_pdf', $params);
        }

        return $this->render('product/labels.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/app/admin/products/labels/pdf', name: 'app_product_labels_pdf', methods: ['GET'])]
    public function pdf(
        Request $request,
        ProductRepository $productRepository,
        BarcodeGeneratorService $barcodeGenerator,
        PdfService $pdfService
    ): Response {
        $business = $this->requireBusinessContext();

        $productIds = $this->parseIds((string) $request->query->get('products', ''));
        $categoryIds = $this->parseIds((string) $request->query->get('categories', ''));
        $brandIds = $this->parseIds((string) $request->query->get('brands', ''));
        $updatedSince = $this->parseDate((string) $request->query->get('updatedSince', ''));

        if ($productIds !== []) {
            $products = $productRepository->findBy(
                ['business' => $business, 'id' => $productIds],
                ['name' => 'ASC']
            );
        } else {
            $products = $productRepository->findForLabelFilters($business, $categoryIds, $brandIds, $updatedSince);
        }

        $includeBarcode = $this->toBool($request->query->get('includeBarcode', '0'));
        $barcodeSource = $request->query->get('barcodeSource') === 'sku' ? 'sku' : 'ean';
        $showPrice = $this->toBool($request->query->get('showPrice', '0'));
        $showOnlyName = $this->toBool($request->query->get('showOnlyName', '0'));
        $labelsPerProduct = max(1, (int) $request->query->get('labelsPerProduct', 1));

        $labels = [];
        foreach ($products as $product) {
            $barcodeValue = $this->resolveBarcodeValue($product, $includeBarcode, $barcodeSource);
            $barcodeDataUri = null;

            if ($barcodeValue !== null) {
                $barcodeType = $this->resolveBarcodeType($barcodeSource, $barcodeValue);
                $barcodeDataUri = $barcodeGenerator->generatePngDataUri($barcodeValue, $barcodeType);
            }

            for ($i = 0; $i < $labelsPerProduct; $i++) {
                $labels[] = [
                    'product' => $product,
                    'barcodeValue' => $barcodeValue,
                    'barcodeDataUri' => $barcodeDataUri,
                ];
            }
        }

        return $pdfService->render('product/labels_pdf.html.twig', [
            'business' => $business,
            'labels' => $labels,
            'options' => [
                'includeBarcode' => $includeBarcode,
                'barcodeSource' => $barcodeSource,
                'showPrice' => $showPrice,
                'showOnlyName' => $showOnlyName,
            ],
        ], 'etiquetas-productos.pdf');
    }

    private function resolveBarcodeValue(Product $product, bool $includeBarcode, string $barcodeSource): ?string
    {
        if (!$includeBarcode) {
            return null;
        }

        if ($barcodeSource === 'sku') {
            return $product->getSku();
        }

        $barcode = $product->getBarcode();
        if ($barcode === null || $barcode === '') {
            return null;
        }

        return $barcode;
    }

    private function resolveBarcodeType(string $barcodeSource, string $value): string
    {
        if ($barcodeSource === 'sku') {
            return 'CODE128';
        }

        if (preg_match('/^\d{13}$/', $value) === 1) {
            return 'EAN13';
        }

        return 'CODE128';
    }

    /**
     * @param iterable<int, object> $entities
     *
     * @return int[]
     */
    private function extractIds(iterable $entities): array
    {
        $ids = [];

        foreach ($entities as $entity) {
            if (method_exists($entity, 'getId')) {
                $id = $entity->getId();
                if (is_int($id)) {
                    $ids[] = $id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return int[]
     */
    private function parseIds(string $input): array
    {
        if ($input === '') {
            return [];
        }

        $parts = array_filter(array_map('trim', explode(',', $input)));
        $ids = [];

        foreach ($parts as $part) {
            if (ctype_digit($part)) {
                $ids[] = (int) $part;
            }
        }

        return array_values(array_unique($ids));
    }

    private function serializeIds(array $ids): string
    {
        return implode(',', $ids);
    }

    private function parseDate(string $input): ?\DateTimeImmutable
    {
        if ($input === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $input);

        return $date === false ? null : $date->setTime(0, 0, 0);
    }

    private function toBool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function requireBusinessContext(): Business
    {
        return $this->businessContext->requireCurrentBusiness();
    }
}
