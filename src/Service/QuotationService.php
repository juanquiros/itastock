<?php

namespace App\Service;

use App\Entity\Business;
use App\Entity\BusinessArcaConfig;
use App\Entity\Customer;
use App\Entity\Product;
use App\Entity\Quotation;
use App\Entity\QuotationItem;
use App\Entity\User;
use App\Repository\BusinessArcaConfigRepository;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class QuotationService
{
    public function __construct(
        private readonly PricingService $pricingService,
        private readonly BusinessArcaConfigRepository $arcaConfigRepository,
    ) {
    }

    /**
     * @param array<int, mixed> $itemsData
     * @param array<int, Product> $productIndex
     */
    public function buildAndPersistFromPosPayload(Business $business, User $user, ?Customer $customer, array $itemsData, array $productIndex): Quotation
    {
        $quotation = new Quotation();
        $quotation->setBusiness($business);
        $quotation->setCreatedBy($user);
        $quotation->setCustomer($customer);

        $priceList = $this->pricingService->resolveAppliedPriceList($business, $customer);
        $quotation->setPriceListIdUsed($priceList?->getId());
        $quotation->setPriceListNameUsed($priceList?->getName());

        $arcaConfig = $this->arcaConfigRepository->getOrCreate($business);

        $subtotalCents = 0;
        foreach ($itemsData as $row) {
            $productId = (int) ($row['product_id'] ?? 0);
            $qty = $this->normalizeQuantity($row['qty'] ?? null);

            if ($qty === null || bccomp($qty, '0', 3) <= 0) {
                continue;
            }

            if ($productId > 0) {
                if (!isset($productIndex[$productId])) {
                    throw new AccessDeniedException('El producto no pertenece a tu comercio.');
                }

                $product = $productIndex[$productId];
                $this->assertProductQtyRules($product, $qty);

                $unitPrice = $this->pricingService->resolveUnitPrice($product, $customer);
                [$lineTotal, $lineCents] = $this->calculateLineTotal(number_format($unitPrice, 2, '.', ''), $qty);
                $subtotalCents += $lineCents;

                $item = new QuotationItem();
                $item->setProduct($product);
                $item->setDescription($this->buildSaleItemDescription($product));
                $item->setQty($qty);
                $item->setUnitPrice(number_format($unitPrice, 2, '.', ''));
                $item->setLineSubtotal($lineTotal);
                $item->setLineDiscount('0.00');
                $item->setLineTotal($lineTotal);
                $quotation->addItem($item);

                continue;
            }

            $customItem = $this->buildCustomQuotationItem($row, $qty, $arcaConfig);
            if (!$customItem['ok']) {
                throw new AccessDeniedException((string) ($customItem['error'] ?? 'Ítem inválido.'));
            }

            /** @var QuotationItem $item */
            $item = $customItem['item'];
            $quotation->addItem($item);
            $subtotalCents += (int) $customItem['lineCents'];
        }

        if (count($quotation->getItems()) === 0) {
            throw new AccessDeniedException('Agregá al menos un producto para generar el presupuesto.');
        }

        $total = $this->formatCents($subtotalCents);
        $quotation->setSubtotal($total);
        $quotation->setTotal($total);

        return $quotation;
    }

    private function assertProductQtyRules(Product $product, string $qty): void
    {
        $qtyStep = $product->getQtyStep() ?? '0.100';
        $allowsFractional = $product->allowsFractionalQty();

        if ($product->getUomBase() === Product::UOM_UNIT && $allowsFractional === false && !$this->isIntegerQuantity($qty)) {
            throw new AccessDeniedException(sprintf('La cantidad de %s debe ser entera.', $product->getName()));
        }

        if ($allowsFractional === false && bccomp($qty, '1', 3) < 0) {
            throw new AccessDeniedException(sprintf('La cantidad mínima para %s es 1.', $product->getName()));
        }

        if ($allowsFractional && !$this->isMultipleOfStep($qty, $qtyStep)) {
            throw new AccessDeniedException(sprintf('La cantidad de %s debe ser múltiplo de %s.', $product->getName(), $qtyStep));
        }
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{ok: bool, error?: string, item?: QuotationItem, lineCents?: int}
     */
    private function buildCustomQuotationItem(array $row, string $qty, BusinessArcaConfig $arcaConfig): array
    {
        $description = trim((string) ($row['description'] ?? ''));
        if (mb_strlen($description) < 3) {
            return ['ok' => false, 'error' => 'La descripción del producto no cargado debe tener al menos 3 caracteres.'];
        }

        $unitPrice = $this->normalizeMoney($row['unit_price'] ?? null);
        if ($unitPrice === null || bccomp($unitPrice, '0', 2) <= 0) {
            return ['ok' => false, 'error' => 'El precio unitario del producto no cargado debe ser mayor a 0.'];
        }

        $ivaRate = $this->resolveCustomIvaRate($row['iva_rate'] ?? null, $arcaConfig);
        if ($ivaRate === null) {
            return ['ok' => false, 'error' => 'El IVA del producto no cargado debe estar entre 0 y 100.'];
        }

        [$lineTotal, $lineCents] = $this->calculateLineTotal($unitPrice, $qty);

        $item = new QuotationItem();
        $item->setProduct(null);
        $item->setDescription($description);
        $item->setQty($qty);
        $item->setUnitPrice($unitPrice);
        $item->setLineSubtotal($lineTotal);
        $item->setLineDiscount('0.00');
        $item->setLineTotal($lineTotal);
        $item->setIvaRate($ivaRate);

        return [
            'ok' => true,
            'item' => $item,
            'lineCents' => $lineCents,
        ];
    }

    private function resolveCustomIvaRate(mixed $inputRate, BusinessArcaConfig $arcaConfig): ?string
    {
        if ($inputRate !== null && $inputRate !== '') {
            return $this->normalizeIvaRate($inputRate);
        }

        if (!$arcaConfig->isGenericItemIvaEnabled()) {
            return '0.00';
        }

        return $this->normalizeIvaRate($arcaConfig->getGenericItemIvaRate()) ?? '0.00';
    }

    private function normalizeIvaRate(mixed $value): ?string
    {
        $normalized = $this->normalizeMoney($value);
        if ($normalized === null) {
            return null;
        }

        if (bccomp($normalized, '0', 2) < 0 || bccomp($normalized, '100', 2) > 0) {
            return null;
        }

        return $normalized;
    }

    private function normalizeMoney(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $stringValue = is_string($value) ? trim($value) : (string) $value;
        $stringValue = str_replace(',', '.', $stringValue);

        if ($stringValue === '') {
            return null;
        }

        if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $stringValue)) {
            return null;
        }

        return number_format((float) $stringValue, 2, '.', '');
    }

    private function buildSaleItemDescription(Product $product): string
    {
        $summary = $product->getCharacteristicsSummary();
        if ($summary === null) {
            return (string) $product->getName();
        }

        return sprintf('%s (%s)', $product->getName(), $summary);
    }

    private function normalizeQuantity(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $stringValue = is_string($value) ? trim($value) : (string) $value;
        $stringValue = str_replace(',', '.', $stringValue);

        if ($stringValue === '') {
            return null;
        }

        if (!preg_match('/^\d+(?:\.\d{1,3})?$/', $stringValue)) {
            return null;
        }

        return bcadd($stringValue, '0', 3);
    }

    private function isIntegerQuantity(string $qty): bool
    {
        $normalized = bcadd($qty, '0', 3);

        if (!str_contains($normalized, '.')) {
            return true;
        }

        [, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');

        return (int) rtrim($fraction, '0') === 0;
    }

    private function isMultipleOfStep(string $qty, string $step): bool
    {
        if (bccomp($step, '0', 3) <= 0) {
            return false;
        }

        $qtyInt = $this->decimalToIntScale($qty, 3);
        $stepInt = $this->decimalToIntScale($step, 3);

        if ($stepInt === 0) {
            return false;
        }

        return $qtyInt % $stepInt === 0;
    }

    /** @return array{0: string, 1: int} */
    private function calculateLineTotal(string $unitPrice, string $qty): array
    {
        $unitCents = $this->decimalToIntScale($unitPrice, 2);
        $qtyScaled = $this->decimalToIntScale($qty, 3);

        $raw = $unitCents * $qtyScaled;
        $lineCents = intdiv($raw, 1000);
        $remainder = abs($raw % 1000);

        if ($remainder >= 500) {
            $lineCents += $raw >= 0 ? 1 : -1;
        }

        return [$this->formatCents($lineCents), $lineCents];
    }

    private function formatCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $absolute = abs($cents);
        $major = intdiv($absolute, 100);
        $minor = $absolute % 100;

        return sprintf('%s%d.%02d', $sign, $major, $minor);
    }

    private function decimalToIntScale(string $value, int $scale): int
    {
        $normalized = bcadd($value, '0', $scale);
        $sign = 1;

        if (str_starts_with($normalized, '-')) {
            $sign = -1;
            $normalized = substr($normalized, 1);
        }

        [$integer, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');
        $fraction = str_pad($fraction, $scale, '0');

        $intString = ltrim($integer.$fraction, '0');
        if ($intString === '') {
            $intString = '0';
        }

        return (int) $intString * $sign;
    }
}
