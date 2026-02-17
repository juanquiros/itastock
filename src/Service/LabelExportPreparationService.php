<?php

namespace App\Service;

use App\Entity\Business;
use App\Entity\Product;
use App\Repository\ProductRepository;

class LabelExportPreparationService
{
    public function __construct(
        private readonly ProductRepository $productRepository,
        private readonly BarcodeGeneratorService $barcodeGenerator,
    ) {
    }

    /** @return Product[] */
    public function findProducts(Business $business, array $filters): array
    {
        $productIds = $this->parseIds((string) ($filters['products'] ?? ''));
        $categoryIds = $this->parseIds((string) ($filters['categories'] ?? ''));
        $brandIds = $this->parseIds((string) ($filters['brands'] ?? ''));
        $updatedSince = $this->parseDate((string) ($filters['updatedSince'] ?? ''));

        if ($productIds !== []) {
            return $this->productRepository->findBy(['business' => $business, 'id' => $productIds], ['name' => 'ASC']);
        }

        return $this->productRepository->findForLabelFilters($business, $categoryIds, $brandIds, $updatedSince);
    }

    public function toBool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public function resolveBarcodeValue(Product $product, bool $includeBarcode, string $barcodeSource): ?string
    {
        if (!$includeBarcode) {
            return null;
        }

        if ($barcodeSource === 'sku') {
            return $product->getSku();
        }

        $barcode = $product->getBarcode();

        return ($barcode === null || $barcode === '') ? null : $barcode;
    }

    public function resolveBarcodeType(string $barcodeSource, string $value): string
    {
        if ($barcodeSource === 'sku') {
            return 'CODE128';
        }

        return preg_match('/^\d{13}$/', $value) === 1 ? 'EAN13' : 'CODE128';
    }

    /** @param Product[] $products */
    public function buildLabels(array $products, array $filters): array
    {
        $includeBarcode = $this->toBool($filters['includeBarcode'] ?? '0');
        $barcodeSource = ($filters['barcodeSource'] ?? 'ean') === 'sku' ? 'sku' : 'ean';
        $labelsPerProduct = max(1, (int) ($filters['labelsPerProduct'] ?? 1));

        $labels = [];
        foreach ($products as $product) {
            $barcodeValue = $this->resolveBarcodeValue($product, $includeBarcode, $barcodeSource);
            $barcodeDataUri = null;

            if ($barcodeValue !== null) {
                $barcodeType = $this->resolveBarcodeType($barcodeSource, $barcodeValue);
                $barcodeDataUri = $this->barcodeGenerator->generatePngDataUri($barcodeValue, $barcodeType);
            }

            for ($i = 0; $i < $labelsPerProduct; ++$i) {
                $labels[] = [
                    'product' => $product,
                    'barcodeValue' => $barcodeValue,
                    'barcodeDataUri' => $barcodeDataUri,
                ];
            }
        }

        return $labels;
    }

    private function parseDate(string $input): ?\DateTimeImmutable
    {
        if ($input === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $input);

        return $date === false ? null : $date->setTime(0, 0, 0);
    }

    /** @return int[] */
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
}
