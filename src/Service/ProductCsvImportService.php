<?php

namespace App\Service;

use App\Entity\Business;
use App\Entity\Product;
use App\Entity\User;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class ProductCsvImportService
{
    public function __construct(
        private readonly ProductRepository $productRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array{created:int, updated:int, failed: array<int, array{line:int, reason:string}>}
     */
    public function import(UploadedFile $file, Business $business, User $user, bool $dryRun = false): array
    {
        $results = [
            'created' => 0,
            'updated' => 0,
            'failed' => [],
        ];

        $csv = new \SplFileObject($file->getPathname());
        $csv->setFlags(\SplFileObject::READ_CSV | \SplFileObject::SKIP_EMPTY | \SplFileObject::DROP_NEW_LINE);
        $csv->setCsvControl(';');

        $header = null;

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

                    break;
                }

                continue;
            }

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

            $product = $this->productRepository->findOneByBusinessAndSku($business, $mapped['sku']);
            $isNew = $product === null;

            if ($isNew) {
                $product = new Product();
                $product->setBusiness($business);
            }

            if (!$dryRun) {
                $product->setSku($mapped['sku']);
                $product->setName($mapped['name']);
                $product->setBasePrice($mapped['basePrice']);

                if ($mapped['barcode'] !== null) {
                    $product->setBarcode($mapped['barcode']);
                }

                if ($mapped['cost'] !== null) {
                    $product->setCost($mapped['cost']);
                }

                if ($mapped['stockMin'] !== null) {
                    $product->setStockMin($mapped['stockMin']);
                }

                if ($mapped['isActive'] !== null) {
                    $product->setIsActive($mapped['isActive']);
                }

                if ($isNew) {
                    $this->entityManager->persist($product);
                }
            }

            $results[$isNew ? 'created' : 'updated']++;
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        return $results;
    }

    /**
     * @param array<int, string|null> $row
     * @return array<string, mixed>
     */
    private function mapRow(array $header, array $row): ?array
    {
        $values = array_fill_keys(array_keys($header), null);

        foreach ($header as $name => $index) {
            if (!array_key_exists($index, $row)) {
                continue;
            }

            $values[$name] = $row[$index] !== null ? trim((string) $row[$index]) : null;
        }

        if ($values['sku'] === null || $values['name'] === null || $values['basePrice'] === null) {
            return null;
        }

        $isActive = null;

        if ($values['isActive'] !== null && $values['isActive'] !== '') {
            $parsed = $this->parseBool($values['isActive']);
            if ($parsed === null) {
                return [
                    'sku' => $values['sku'],
                    'name' => $values['name'],
                    'basePrice' => $values['basePrice'],
                    'barcode' => $values['barcode'] ?: null,
                    'cost' => $values['cost'] ?: null,
                    'stockMin' => $values['stockMin'] ?: null,
                    'isActive' => null,
                    'invalid' => 'isActive debe ser 0/1 o true/false',
                ];
            }

            $isActive = $parsed;
        }

        return [
            'sku' => $values['sku'],
            'name' => $values['name'],
            'barcode' => $values['barcode'] ?: null,
            'cost' => $values['cost'] !== null && $values['cost'] !== '' ? $values['cost'] : null,
            'basePrice' => $values['basePrice'],
            'stockMin' => $values['stockMin'] !== null && $values['stockMin'] !== '' ? $values['stockMin'] : null,
            'isActive' => $isActive,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function validateRow(array &$row): ?string
    {
        if ($row['sku'] === '' || $row['name'] === '') {
            return 'SKU y nombre son obligatorios';
        }

        if (!$this->isNonNegativeNumber($row['basePrice'])) {
            return 'basePrice debe ser un número mayor o igual a cero';
        }

        $row['basePrice'] = number_format((float) $row['basePrice'], 2, '.', '');

        if ($row['cost'] !== null) {
            if (!$this->isNonNegativeNumber($row['cost'])) {
                return 'cost debe ser un número mayor o igual a cero';
            }

            $row['cost'] = number_format((float) $row['cost'], 2, '.', '');
        }

        if ($row['stockMin'] !== null) {
            if (!$this->isNonNegativeDecimal($row['stockMin'])) {
                return 'stockMin debe ser un número mayor o igual a cero (hasta 3 decimales)';
            }

            $row['stockMin'] = number_format((float) $row['stockMin'], 3, '.', '');
        }

        if (array_key_exists('invalid', $row)) {
            return $row['invalid'];
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

            $map[strtolower(trim((string) $column))] = $index;
        }

        return $map;
    }

    /**
     * @param array<string, int> $header
     */
    private function hasRequiredColumns(array $header): bool
    {
        return isset($header['sku'], $header['name'], $header['baseprice']);
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
}
