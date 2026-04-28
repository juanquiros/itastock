<?php

namespace App\Service;

use App\Entity\Business;
use App\Entity\BusinessArcaConfig;
use App\Entity\Customer;
use App\Entity\Product;
use App\Entity\Quotation;
use App\Entity\QuotationItem;
use App\Entity\User;
use App\Repository\PriceListRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class QuotationService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PricingService $pricingService,
        private readonly PriceListRepository $priceListRepository,
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $itemsData
     */
    public function createFromPosPayload(Business $business, User $user, ?Customer $customer, array $itemsData, ?string $cashierComment = null): Quotation
    {
        if (count($itemsData) === 0) {
            throw new AccessDeniedException('Agregá al menos un producto para generar el presupuesto.');
        }

        $productIds = [];
        foreach ($itemsData as $row) {
            $id = (int) ($row['product_id'] ?? 0);
            if ($id > 0) {
                $productIds[] = $id;
            }
        }

        $productIndex = [];
        if ($productIds !== []) {
            $products = $this->entityManager->getRepository(Product::class)->createQueryBuilder('p')
                ->andWhere('p.business = :business')
                ->andWhere('p.id IN (:ids)')
                ->setParameter('business', $business)
                ->setParameter('ids', array_values(array_unique($productIds)))
                ->getQuery()
                ->getResult();

            foreach ($products as $product) {
                $productIndex[$product->getId()] = $product;
            }
        }

        $quotation = new Quotation();
        $quotation->setBusiness($business);
        $quotation->setCreatedBy($user);
        $quotation->setCustomer($customer);
        $quotation->setCashierComment($cashierComment ? trim($cashierComment) : null);

        $priceList = $customer?->getPriceList() ?? $this->priceListRepository->findDefaultActiveForBusiness($business);
        $quotation->setPriceListIdUsed($priceList?->getId());
        $quotation->setPriceListNameUsed($priceList?->getName());

        $arcaConfig = $this->entityManager->getRepository(BusinessArcaConfig::class)->findOneBy(['business' => $business]);
        $subtotalCents = 0;

        foreach ($itemsData as $row) {
            $qty = $this->normalizeQuantity($row['qty'] ?? null);
            if ($qty === null || bccomp($qty, '0', 3) <= 0) {
                continue;
            }

            $productId = (int) ($row['product_id'] ?? 0);
            if ($productId > 0) {
                if (!isset($productIndex[$productId])) {
                    throw new AccessDeniedException('Producto inválido para el presupuesto.');
                }

                $product = $productIndex[$productId];
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

                if (!$business->allowsNegativeStock() && bccomp($qty, $product->getStock(), 3) === 1) {
                    throw new AccessDeniedException(sprintf('No hay stock suficiente para %s.', $product->getName()));
                }

                $unitPrice = $this->pricingService->resolveUnitPrice($product, $customer);
                [$lineTotal, $lineCents] = $this->calculateLineTotal(number_format($unitPrice, 2, '.', ''), $qty);
                $subtotalCents += $lineCents;

                $item = new QuotationItem();
                $item->setProduct($product);
                $item->setDescription($this->buildItemDescription($product));
                $item->setQty($qty);
                $item->setUnitPrice(number_format($unitPrice, 2, '.', ''));
                $item->setLineSubtotal($lineTotal);
                $item->setLineDiscount('0.00');
                $item->setLineTotal($lineTotal);
                $item->setIvaRate($product->getIvaRate());
                $quotation->addItem($item);

                continue;
            }

            $description = trim((string) ($row['description'] ?? ''));
            if (mb_strlen($description) < 3) {
                throw new AccessDeniedException('La descripción del producto no cargado debe tener al menos 3 caracteres.');
            }

            $unitPrice = $this->normalizeMoney($row['unit_price'] ?? null);
            if ($unitPrice === null || bccomp($unitPrice, '0', 2) <= 0) {
                throw new AccessDeniedException('El precio unitario del producto no cargado debe ser mayor a 0.');
            }

            $ivaRate = $this->resolveCustomIvaRate($row['iva_rate'] ?? null, $arcaConfig);
            if ($ivaRate === null) {
                throw new AccessDeniedException('El IVA del producto no cargado debe estar entre 0 y 100.');
            }

            [$lineTotal, $lineCents] = $this->calculateLineTotal($unitPrice, $qty);
            $subtotalCents += $lineCents;

            $item = new QuotationItem();
            $item->setProduct(null);
            $item->setDescription($description);
            $item->setQty($qty);
            $item->setUnitPrice($unitPrice);
            $item->setLineSubtotal($lineTotal);
            $item->setLineDiscount('0.00');
            $item->setLineTotal($lineTotal);
            $item->setIvaRate($ivaRate);
            $quotation->addItem($item);
        }

        if (count($quotation->getItems()) === 0) {
            throw new AccessDeniedException('Agregá al menos un producto válido para generar el presupuesto.');
        }

        if ($subtotalCents < 0) {
            throw new AccessDeniedException('El total del presupuesto no puede ser negativo.');
        }

        $total = $this->formatCents($subtotalCents);
        if (bccomp($total, '0.00', 2) < 0) {
            throw new AccessDeniedException('El total del presupuesto no puede ser negativo.');
        }

        $quotation->setSubtotal($total);
        $quotation->setTotal($total);

        $this->entityManager->persist($quotation);
        $this->entityManager->flush();

        return $quotation;
    }

    private function buildItemDescription(Product $product): string
    {
        $summary = $product->getCharacteristicsSummary();

        return $summary === null ? (string) $product->getName() : sprintf('%s (%s)', $product->getName(), $summary);
    }

    private function normalizeQuantity(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $stringValue = is_string($value) ? trim($value) : (string) $value;
        $stringValue = str_replace(',', '.', $stringValue);
        if ($stringValue === '' || !preg_match('/^\d+(?:\.\d{1,3})?$/', $stringValue)) {
            return null;
        }

        return bcadd($stringValue, '0', 3);
    }

    private function normalizeMoney(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $stringValue = is_string($value) ? trim($value) : (string) $value;
        $stringValue = str_replace(',', '.', $stringValue);
        if ($stringValue === '' || !preg_match('/^\d+(?:\.\d{1,2})?$/', $stringValue)) {
            return null;
        }

        return number_format((float) $stringValue, 2, '.', '');
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

    private function resolveCustomIvaRate(mixed $inputRate, ?BusinessArcaConfig $arcaConfig): ?string
    {
        if ($inputRate !== null && $inputRate !== '') {
            return $this->normalizeIvaRate($inputRate);
        }

        if (!$arcaConfig?->isGenericItemIvaEnabled()) {
            return '0.00';
        }

        return $this->normalizeIvaRate($arcaConfig->getGenericItemIvaRate()) ?? '0.00';
    }

    private function isIntegerQuantity(string $qty): bool
    {
        $normalized = bcadd($qty, '0', 3);
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

        return $stepInt !== 0 && ($qtyInt % $stepInt === 0);
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

        return sprintf('%s%d.%02d', $sign, intdiv($absolute, 100), $absolute % 100);
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

        return $sign * (int) ($intString === '' ? '0' : $intString);
    }
}
