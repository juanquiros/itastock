<?php

namespace App\Service;

use App\Entity\Business;
use App\Entity\StockMovement;
use App\Entity\User;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class StockCsvImportService
{
    public function __construct(
        private readonly ProductRepository $productRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array{applied:int, failed: array<int, array{line:int, reason:string}>}
     */
    public function import(UploadedFile $file, Business $business, User $user, bool $dryRun = false): array
    {
        $results = [
            'applied' => 0,
            'failed' => [],
        ];

        $csv = new \SplFileObject($file->getPathname());
        $csv->setFlags(\SplFileObject::READ_CSV | \SplFileObject::SKIP_EMPTY | \SplFileObject::DROP_NEW_LINE);
        $csv->setCsvControl(';');

        $header = null;
        $runningStock = [];

        foreach ($csv as $lineNumber => $row) {
            if ($row === [null] || $row === false) {
                continue;
            }

            if ($header === null) {
                $header = $this->normalizeHeader($row);

                if (!$this->hasRequiredColumns($header)) {
                    $results['failed'][] = [
                        'line' => 1,
                        'reason' => 'El CSV debe contener las columnas identifier;quantity_delta;note',
                    ];

                    break;
                }

                continue;
            }

            $line = $lineNumber + 1;
            $data = $this->mapRow($header, $row);

            if ($data === null) {
                continue;
            }

            if (isset($data['error'])) {
                $results['failed'][] = ['line' => $line, 'reason' => $data['error']];
                continue;
            }

            $identifier = $data['identifier'];
            $delta = $data['quantity_delta'];
            $note = $data['note'];

            $product = $this->productRepository->findOneByBusinessAndSku($business, $identifier)
                ?? $this->productRepository->findOneByBusinessAndBarcode($business, $identifier);

            if ($product === null) {
                $results['failed'][] = ['line' => $line, 'reason' => 'Producto no encontrado por SKU o código de barras'];
                continue;
            }

            $productId = (int) $product->getId();
            $current = $runningStock[$productId] ?? $product->getStock();
            $newStock = bcadd($current, $delta, 3);

            if (bccomp($newStock, '0', 3) < 0) {
                $results['failed'][] = ['line' => $line, 'reason' => 'El ajuste dejaría el stock en negativo'];
                continue;
            }

            $runningStock[$productId] = $newStock;

            if (!$dryRun) {
                $movement = new StockMovement();
                $movement->setProduct($product);
                $movement->setType(StockMovement::TYPE_ADJUST);
                $movement->setQty($delta);
                $movement->setReference(trim($note) !== '' ? 'CSV · '.$note : 'CSV');
                $movement->setCreatedBy($user);

                $product->adjustStock($delta);

                $this->entityManager->persist($movement);
            }

            $results['applied']++;
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        return $results;
    }

    /**
     * @param array<int, string|null> $row
     * @return array<string, mixed>|null
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

        if ($values['identifier'] === null || $values['identifier'] === '') {
            return [
                'error' => 'identifier es obligatorio',
            ];
        }

        $delta = $this->parseQuantityDelta($values['quantity_delta']);
        if ($delta === null) {
            return [
                'identifier' => $values['identifier'],
                'error' => 'quantity_delta debe ser un número con hasta 3 decimales',
            ];
        }

        return [
            'identifier' => $values['identifier'],
            'quantity_delta' => $delta,
            'note' => $values['note'] ?? '',
        ];
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
        return isset($header['identifier'], $header['quantity_delta']);
    }

    private function parseQuantityDelta(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = str_replace(',', '.', $value);

        if (!preg_match('/^-?\d+(?:\.\d{1,3})?$/', $normalized)) {
            return null;
        }

        return bcadd($normalized, '0', 3);
    }
}
