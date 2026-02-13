<?php

namespace App\Service;

use App\Entity\Brand;
use App\Entity\Business;
use App\Entity\Category;
use App\Entity\Product;
use App\Entity\User;
use App\Repository\BrandRepository;
use App\Repository\CategoryRepository;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;

class ProductCsvImportService
{
    private const BATCH_SIZE = 200;

    public function __construct(
        private readonly ProductRepository $productRepository,
        private readonly CategoryRepository $categoryRepository,
        private readonly BrandRepository $brandRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly SluggerInterface $slugger,
    ) {
    }

    /**
     * @return array{created:int, updated:int, failed: array<int, array{line:int, reason:string}>, fileErrors: string[], rowsRead: int}
     */
    public function import(UploadedFile $file, Business $business, User $user, bool $dryRun = false): array
    {
        $results = [
            'created' => 0,
            'updated' => 0,
            'failed' => [],
            'fileErrors' => [],
            'rowsRead' => 0,
        ];

        $csv = new \SplFileObject($file->getPathname());
        $csv->setFlags(\SplFileObject::READ_CSV | \SplFileObject::SKIP_EMPTY | \SplFileObject::DROP_NEW_LINE);
        $csv->setCsvControl(';');

        $header = null;
        $productCache = [];
        $barcodeCache = [];
        $categoryCache = $this->buildCategoryCache($business);
        $brandCache = $this->buildBrandCache($business);
        $usedBrandSlugs = $this->buildBrandSlugCache($business, $brandCache);
        $processed = 0;
        $dataRows = 0;

        foreach ($csv as $lineNumber => $row) {
            if ($row === [null] || $row === false) {
                continue;
            }

            if ($header === null) {
                $header = $this->normalizeHeader($row);

                if (!$this->hasRequiredColumns($header)) {
                    $results['failed'][] = [
                        'line' => 1,
                        'reason' => 'El CSV debe contener las columnas sku;name;basePrice',
                    ];
                    $results['fileErrors'][] = 'Encabezado inválido o separador incorrecto. Verificá que el archivo use punto y coma (;).';

                    break;
                }

                continue;
            }

            ++$dataRows;
            $line = $lineNumber + 1;
            $mapped = $this->mapRow($header, $row);

            if ($mapped === null) {
                continue;
            }

            $validationError = $this->validateRow($mapped);
            if ($validationError !== null) {
                $results['failed'][] = ['line' => $line, 'reason' => $validationError];
                continue;
            }

            $sku = (string) $mapped['sku'];
            $product = $productCache[$sku] ?? null;
            if (!$product instanceof Product && !array_key_exists($sku, $productCache)) {
                $product = $this->productRepository->findOneByBusinessAndSku($business, $sku);
                $productCache[$sku] = $product;
            }

            $isNew = !$product instanceof Product;
            if ($isNew) {
                $product = new Product();
                $product->setBusiness($business);
            }

            $barcode = $mapped['barcode'] ?? null;
            if ($barcode !== null) {
                $barcodeConflict = $this->barcodeConflicts($business, (string) $barcode, $product, $barcodeCache);
                if ($barcodeConflict) {
                    $results['failed'][] = ['line' => $line, 'reason' => 'barcode ya existe en otro producto del comercio'];
                    continue;
                }
            }

            $category = $this->resolveCategory($business, $mapped['category'] ?? null, $categoryCache, $dryRun);
            $brand = $this->resolveBrand($business, $mapped['brand'] ?? null, $brandCache, $usedBrandSlugs, $dryRun);

            if (!$dryRun) {
                $product->setSku($sku);
                $product->setName((string) $mapped['name']);
                $product->setBasePrice((string) $mapped['basePrice']);

                if (array_key_exists('barcode', $mapped)) {
                    $product->setBarcode($mapped['barcode']);
                }

                if (array_key_exists('cost', $mapped) && $mapped['cost'] !== null) {
                    $product->setCost((string) $mapped['cost']);
                }

                if (array_key_exists('stockMin', $mapped) && $mapped['stockMin'] !== null) {
                    $product->setStockMin((string) $mapped['stockMin']);
                }

                if (array_key_exists('isActive', $mapped) && $mapped['isActive'] !== null) {
                    $product->setIsActive((bool) $mapped['isActive']);
                }

                if (array_key_exists('category', $mapped)) {
                    $product->setCategory($category);
                }

                if (array_key_exists('brand', $mapped)) {
                    $product->setBrand($brand);
                }

                if (array_key_exists('characteristics', $mapped)) {
                    $product->setCharacteristics($mapped['characteristics'] ?? []);
                }

                if (array_key_exists('ivaRate', $mapped) && $mapped['ivaRate'] !== null) {
                    $product->setIvaRate((string) $mapped['ivaRate']);
                }

                if (array_key_exists('targetStock', $mapped) && $mapped['targetStock'] !== null) {
                    $product->setTargetStock((string) $mapped['targetStock']);
                }

                if (array_key_exists('uomBase', $mapped) && $mapped['uomBase'] !== null) {
                    $product->setUomBase((string) $mapped['uomBase']);
                }

                if (array_key_exists('allowsFractionalQty', $mapped) && $mapped['allowsFractionalQty'] !== null) {
                    $product->setAllowsFractionalQty((bool) $mapped['allowsFractionalQty']);
                }

                if (array_key_exists('qtyStep', $mapped) && $mapped['qtyStep'] !== null) {
                    $product->setQtyStep((string) $mapped['qtyStep']);
                }

                if (array_key_exists('supplierSku', $mapped)) {
                    $product->setSupplierSku($mapped['supplierSku']);
                }

                if (array_key_exists('purchasePrice', $mapped) && $mapped['purchasePrice'] !== null) {
                    $product->setPurchasePrice((string) $mapped['purchasePrice']);
                }

                if (array_key_exists('searchText', $mapped)) {
                    $product->setSearchText($mapped['searchText']);
                }

                if ($isNew) {
                    $this->entityManager->persist($product);
                }

                $productCache[$sku] = $product;

                ++$processed;
                if ($processed % self::BATCH_SIZE === 0) {
                    $this->entityManager->flush();
                }
            }

            $results[$isNew ? 'created' : 'updated']++;
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        $results['rowsRead'] = $dataRows;

        if ($dataRows === 0) {
            $results['fileErrors'][] = 'El archivo no contiene filas de datos para procesar.';
        }

        if ($dataRows > 0 && $results['created'] === 0 && $results['updated'] === 0 && count($results['failed']) === 0) {
            $results['fileErrors'][] = 'No se pudieron procesar filas. Revisá columnas requeridas (sku,name,basePrice) y formato de datos.';
        }

        return $results;
    }

    /**
     * @param array<int, string|null> $row
     * @return array<string, mixed>|null
     */
    private function mapRow(array $header, array $row): ?array
    {
        $values = [];

        foreach ($header as $name => $index) {
            if (!array_key_exists($index, $row)) {
                continue;
            }

            $raw = $row[$index];
            $values[$name] = $raw !== null ? trim((string) $raw) : null;
        }

        if ($values === [] || array_filter($values, static fn (mixed $value): bool => $value !== null && $value !== '') === []) {
            return null;
        }

        $mapped = [
            'sku' => $values['sku'] ?? '',
            'name' => $values['name'] ?? '',
            'basePrice' => $values['basePrice'] ?? '',
        ];

        if ($mapped['sku'] === '' || $mapped['name'] === '' || $mapped['basePrice'] === '') {
            $mapped['invalid'] = 'sku, name y basePrice son obligatorios';
        }

        foreach (['barcode', 'cost', 'stockMin', 'ivaRate', 'targetStock', 'uomBase', 'qtyStep', 'supplierSku', 'purchasePrice', 'searchText'] as $field) {
            if (array_key_exists($field, $values)) {
                $mapped[$field] = $values[$field] !== '' ? $values[$field] : null;
            }
        }

        if (array_key_exists('isActive', $values) && $values['isActive'] !== null && $values['isActive'] !== '') {
            $parsed = $this->parseBool($values['isActive']);
            if ($parsed === null) {
                $mapped['invalid'] = 'isActive debe ser 0/1 o true/false';
            } else {
                $mapped['isActive'] = $parsed;
            }
        }

        if (array_key_exists('allowsFractionalQty', $values) && $values['allowsFractionalQty'] !== null && $values['allowsFractionalQty'] !== '') {
            $parsed = $this->parseBool($values['allowsFractionalQty']);
            if ($parsed === null) {
                $mapped['invalid'] = 'allowsFractionalQty debe ser 0/1 o true/false';
            } else {
                $mapped['allowsFractionalQty'] = $parsed;
            }
        }

        if (array_key_exists('category', $values)) {
            $mapped['category'] = $values['category'] !== '' ? $values['category'] : null;
        }

        if (array_key_exists('brand', $values)) {
            $mapped['brand'] = $values['brand'] !== '' ? $values['brand'] : null;
        }

        if (array_key_exists('characteristics', $values)) {
            $mapped['characteristics'] = $this->parseCharacteristics($values['characteristics']);
            if (is_string($mapped['characteristics'])) {
                $mapped['invalid'] = $mapped['characteristics'];
                $mapped['characteristics'] = null;
            }
        }

        return $mapped;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function validateRow(array &$row): ?string
    {
        if (array_key_exists('invalid', $row)) {
            return (string) $row['invalid'];
        }

        if ($row['sku'] === '' || $row['name'] === '') {
            return 'SKU y nombre son obligatorios';
        }

        if (!$this->isNonNegativeNumber((string) $row['basePrice'])) {
            return 'basePrice debe ser un número mayor o igual a cero';
        }
        $row['basePrice'] = number_format((float) $row['basePrice'], 2, '.', '');

        foreach (['cost', 'purchasePrice'] as $moneyField) {
            if (array_key_exists($moneyField, $row) && $row[$moneyField] !== null) {
                if (!$this->isNonNegativeNumber((string) $row[$moneyField])) {
                    return sprintf('%s debe ser un número mayor o igual a cero', $moneyField);
                }

                $row[$moneyField] = number_format((float) $row[$moneyField], 2, '.', '');
            }
        }

        foreach (['stockMin', 'targetStock', 'qtyStep'] as $decimalField) {
            if (array_key_exists($decimalField, $row) && $row[$decimalField] !== null) {
                if (!$this->isNonNegativeDecimal((string) $row[$decimalField])) {
                    return sprintf('%s debe ser un número mayor o igual a cero (hasta 3 decimales)', $decimalField);
                }

                $row[$decimalField] = number_format((float) $row[$decimalField], 3, '.', '');
            }
        }

        if (array_key_exists('ivaRate', $row) && $row['ivaRate'] !== null) {
            if (!$this->isNonNegativeNumber((string) $row['ivaRate'])) {
                return 'ivaRate debe ser un número mayor o igual a cero';
            }

            $row['ivaRate'] = number_format((float) $row['ivaRate'], 2, '.', '');
        }

        if (array_key_exists('category', $row) && $row['category'] !== null && mb_strlen((string) $row['category']) > 120) {
            return 'category supera el máximo de 120 caracteres';
        }

        if (array_key_exists('brand', $row) && $row['brand'] !== null && mb_strlen((string) $row['brand']) > 150) {
            return 'brand supera el máximo de 150 caracteres';
        }

        return null;
    }

    private function parseBool(string $value): ?bool
    {
        $normalized = strtolower(trim($value));

        return match ($normalized) {
            '1', 'true', 'yes', 'si', 'sí' => true,
            '0', 'false', 'no' => false,
            default => null,
        };
    }

    /**
     * @param array<int, string|null> $header
     * @return array<string, int>
     */
    private function normalizeHeader(array $header): array
    {
        $map = [];

        foreach ($header as $index => $column) {
            if ($column === null) {
                continue;
            }

            $normalized = strtolower(trim((string) $column));
            $normalized = preg_replace('/^\xEF\xBB\xBF/u', '', $normalized) ?? $normalized;
            $key = match ($normalized) {
                'sku' => 'sku',
                'barcode', 'bar_code' => 'barcode',
                'name' => 'name',
                'cost' => 'cost',
                'baseprice', 'base_price', 'base price' => 'basePrice',
                'stockmin', 'stock_min', 'stock min' => 'stockMin',
                'isactive', 'is_active', 'active' => 'isActive',
                'category', 'categoria' => 'category',
                'brand', 'marca' => 'brand',
                'characteristics', 'caracteristicas', 'características' => 'characteristics',
                'ivarate', 'iva_rate', 'iva rate' => 'ivaRate',
                'targetstock', 'target_stock', 'target stock' => 'targetStock',
                'uombase', 'uom_base', 'uom base' => 'uomBase',
                'allowsfractionalqty', 'allows_fractional_qty', 'allows fractional qty' => 'allowsFractionalQty',
                'qtystep', 'qty_step', 'qty step' => 'qtyStep',
                'suppliersku', 'supplier_sku', 'supplier sku' => 'supplierSku',
                'purchaseprice', 'purchase_price', 'purchase price' => 'purchasePrice',
                'searchtext', 'search_text', 'search text' => 'searchText',
                default => $normalized,
            };

            $map[$key] = $index;
        }

        return $map;
    }

    /**
     * @param array<string, int> $header
     */
    private function hasRequiredColumns(array $header): bool
    {
        return isset($header['sku'], $header['name'], $header['basePrice']);
    }

    private function isNonNegativeNumber(string $value): bool
    {
        return is_numeric($value) && (float) $value >= 0;
    }

    private function isNonNegativeDecimal(string $value): bool
    {
        if (!is_numeric($value)) {
            return false;
        }

        if ((float) $value < 0) {
            return false;
        }

        $parts = explode('.', $value);

        return count($parts) === 1 || strlen($parts[1]) <= 3;
    }

    /**
     * @return array<string, Category>
     */
    private function buildCategoryCache(Business $business): array
    {
        $cache = [];
        foreach ($this->categoryRepository->findBy(['business' => $business]) as $category) {
            $name = trim((string) $category->getName());
            if ($name === '') {
                continue;
            }

            $cache[$this->normalizeLookup($name)] = $category;
        }

        return $cache;
    }

    /**
     * @return array<string, Brand>
     */
    private function buildBrandCache(Business $business): array
    {
        $cache = [];
        foreach ($this->brandRepository->findBy(['business' => $business]) as $brand) {
            $name = trim((string) $brand->getName());
            if ($name === '') {
                continue;
            }

            $cache[$this->normalizeLookup($name)] = $brand;
        }

        return $cache;
    }

    /**
     * @param array<string, Category> $categoryCache
     */
    private function resolveCategory(Business $business, ?string $name, array &$categoryCache, bool $dryRun): ?Category
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        $name = trim($name);
        $key = $this->normalizeLookup($name);

        if (isset($categoryCache[$key])) {
            return $categoryCache[$key];
        }

        if ($dryRun) {
            return null;
        }

        $category = new Category();
        $category->setBusiness($business);
        $category->setName($name);
        $this->entityManager->persist($category);

        $categoryCache[$key] = $category;

        return $category;
    }

    /**
     * @param array<string, Brand> $brandCache
     * @param array<string, true> $usedBrandSlugs
     */
    private function resolveBrand(Business $business, ?string $name, array &$brandCache, array &$usedBrandSlugs, bool $dryRun): ?Brand
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        $name = trim($name);
        $key = $this->normalizeLookup($name);

        if (isset($brandCache[$key])) {
            return $brandCache[$key];
        }

        if ($dryRun) {
            return null;
        }

        $brand = new Brand();
        $brand->setBusiness($business);
        $brand->setName($name);
        $brand->setSlug($this->buildUniqueBrandSlug($name, $usedBrandSlugs));

        $this->entityManager->persist($brand);
        $brandCache[$key] = $brand;

        return $brand;
    }

    /**
     * @param array<string, true> $usedBrandSlugs
     */
    private function buildUniqueBrandSlug(string $name, array &$usedBrandSlugs): string
    {
        $base = strtolower($this->slugger->slug($name)->toString());
        if ($base === '') {
            $base = 'brand';
        }

        $slug = $base;
        $suffix = 2;

        while (isset($usedBrandSlugs[$slug])) {
            $slug = sprintf('%s-%d', $base, $suffix);
            ++$suffix;
        }

        $usedBrandSlugs[$slug] = true;

        return $slug;
    }

    /**
     * @param array<string, Brand> $brandCache
     *
     * @return array<string, true>
     */
    private function buildBrandSlugCache(Business $business, array $brandCache): array
    {
        $used = [];

        foreach ($this->brandRepository->findBy(['business' => $business]) as $brand) {
            $slug = strtolower(trim((string) $brand->getSlug()));
            if ($slug !== '') {
                $used[$slug] = true;
            }
        }

        foreach ($brandCache as $brand) {
            $slug = strtolower(trim((string) $brand->getSlug()));
            if ($slug !== '') {
                $used[$slug] = true;
            }
        }

        return $used;
    }

    private function normalizeLookup(string $value): string
    {
        $normalized = mb_strtolower(trim($value));
        $normalized = strtr($normalized, [
            'á' => 'a',
            'é' => 'e',
            'í' => 'i',
            'ó' => 'o',
            'ú' => 'u',
            'ü' => 'u',
            'ñ' => 'n',
        ]);

        return (string) preg_replace('/\s+/', ' ', $normalized);
    }

    /**
     * @param array<string, int|null> $barcodeCache
     */
    private function barcodeConflicts(Business $business, string $barcode, Product $currentProduct, array &$barcodeCache): bool
    {
        $key = trim($barcode);
        if ($key === '') {
            return false;
        }

        $knownId = $barcodeCache[$key] ?? null;
        if ($knownId === null && !array_key_exists($key, $barcodeCache)) {
            $existing = $this->productRepository->findOneByBusinessAndExactBarcode($business, $key);
            $knownId = $existing?->getId();
            $barcodeCache[$key] = $knownId;
        }

        if ($knownId === null) {
            return false;
        }

        return $currentProduct->getId() !== $knownId;
    }

    /**
     * @return array<string, string>|string|null
     */
    private function parseCharacteristics(?string $raw): array|string|null
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $value = trim($raw);
        if (str_starts_with($value, '{')) {
            try {
                $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return 'characteristics JSON inválido';
            }

            if (!is_array($decoded)) {
                return 'characteristics JSON debe ser un objeto';
            }

            $normalized = [];
            foreach ($decoded as $key => $item) {
                if (!is_scalar($item) && $item !== null) {
                    return 'characteristics JSON solo permite valores escalares';
                }

                $cleanKey = trim((string) $key);
                $cleanValue = trim((string) ($item ?? ''));
                if ($cleanKey === '' || $cleanValue === '') {
                    continue;
                }

                if (array_key_exists($cleanKey, $normalized)) {
                    return 'characteristics no permite claves duplicadas';
                }

                $normalized[$cleanKey] = $cleanValue;
            }

            return $normalized !== [] ? $normalized : null;
        }

        $normalized = [];
        foreach (explode('|', $value) as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue;
            }

            $parts = explode('=', $chunk, 2);
            if (count($parts) !== 2) {
                return 'characteristics key=value inválido';
            }

            $cleanKey = trim($parts[0]);
            $cleanValue = trim($parts[1]);

            if ($cleanKey === '' || $cleanValue === '') {
                continue;
            }

            if (array_key_exists($cleanKey, $normalized)) {
                return 'characteristics no permite claves duplicadas';
            }

            $normalized[$cleanKey] = $cleanValue;
        }

        return $normalized !== [] ? $normalized : null;
    }
}
