<?php

namespace App\Service;

use App\Entity\Discount;
use App\Entity\Sale;
use App\Entity\SaleDiscount;
use App\Entity\SaleItem;
use App\Repository\DiscountRepository;

class DiscountEngine
{
    public function __construct(
        private readonly DiscountRepository $discountRepository,
    ) {
    }

    /**
     * @return array<int, SaleDiscount>
     */
    public function applyDiscounts(Sale $sale, string $paymentMethod): array
    {
        $now = new \DateTimeImmutable();
        $paymentMethod = strtoupper($paymentMethod);
        $saleDiscounts = [];

        foreach ($sale->getSaleDiscounts() as $existing) {
            $sale->removeSaleDiscount($existing);
        }

        $subtotalCents = 0;
        foreach ($sale->getItems() as $item) {
            $lineSubtotal = $item->getLineSubtotal() ?? $item->getLineTotal() ?? '0.00';
            $lineSubtotalCents = $this->decimalToIntScale($lineSubtotal, 2);
            $subtotalCents += $lineSubtotalCents;

            $item->setLineSubtotal($this->formatCents($lineSubtotalCents));
            $item->setLineDiscount($this->formatCents(0));
            $item->setLineTotal($this->formatCents($lineSubtotalCents));
        }

        $sale->setSubtotal($this->formatCents($subtotalCents));
        $sale->setDiscountTotal($this->formatCents(0));
        $sale->setTotal($this->formatCents($subtotalCents));

        $business = $sale->getBusiness();
        if ($business === null || $subtotalCents <= 0) {
            return [];
        }

        $discounts = $this->discountRepository->findActiveForBusiness($business, $now);
        foreach ($discounts as $discount) {
            $conditions = $discount->getConditions();

            [$eligibleItems, $eligibleBaseCents] = $this->resolveEligibleItems($sale, $discount, $conditions);
            if ($eligibleItems === [] || $eligibleBaseCents <= 0) {
                continue;
            }

            if (!$this->matchesConditions($sale, $discount, $conditions, $paymentMethod, $eligibleBaseCents, $subtotalCents)) {
                continue;
            }

            $discountCents = $this->calculateDiscountCents($discount, $eligibleBaseCents);
            if ($discountCents <= 0) {
                continue;
            }

            $discountCents = min($discountCents, $eligibleBaseCents);

            $distributed = $this->distributeDiscount($eligibleItems, $eligibleBaseCents, $discountCents);
            foreach ($distributed as $itemKey => $lineDiscountCents) {
                foreach ($sale->getItems() as $item) {
                    if (spl_object_id($item) === $itemKey) {
                        $current = $this->decimalToIntScale($item->getLineDiscount() ?? '0.00', 2);
                        $updated = $current + $lineDiscountCents;
                        $lineSubtotalCents = $this->decimalToIntScale($item->getLineSubtotal() ?? '0.00', 2);
                        $item->setLineDiscount($this->formatCents($updated));
                        $item->setLineTotal($this->formatCents($lineSubtotalCents - $updated));
                        break;
                    }
                }
            }

            $saleDiscount = new SaleDiscount();
            $saleDiscount->setDiscount($discount);
            $saleDiscount->setDiscountName($discount->getName() ?? 'Descuento');
            $saleDiscount->setActionType($discount->getActionType());
            $saleDiscount->setActionValue($discount->getActionValue() ?? '0.00');
            $saleDiscount->setAppliedAmount($this->formatCents($discountCents));
            $saleDiscount->setMeta([
                'payment_method' => $paymentMethod,
                'eligible_amount' => $this->formatCents($eligibleBaseCents),
            ]);

            $sale->addSaleDiscount($saleDiscount);
            $saleDiscounts[] = $saleDiscount;

            if ($discount->isStackable() === false) {
                break;
            }
        }

        $discountTotalCents = 0;
        foreach ($sale->getItems() as $item) {
            $discountTotalCents += $this->decimalToIntScale($item->getLineDiscount() ?? '0.00', 2);
        }

        $sale->setDiscountTotal($this->formatCents($discountTotalCents));
        $sale->setTotal($this->formatCents($subtotalCents - $discountTotalCents));

        return $saleDiscounts;
    }

    /**
     * @param array<string, mixed> $conditions
     * @return array{0: array<int, SaleItem>, 1: int}
     */
    private function resolveEligibleItems(Sale $sale, Discount $discount, array $conditions): array
    {
        $categories = array_map('intval', $conditions['categories'] ?? []);
        $products = array_map('intval', $conditions['products'] ?? []);
        $excludeCategories = array_map('intval', $conditions['exclude_categories'] ?? []);
        $excludeProducts = array_map('intval', $conditions['exclude_products'] ?? []);

        $eligibleItems = [];
        $eligibleBaseCents = 0;

        foreach ($sale->getItems() as $item) {
            $product = $item->getProduct();
            if ($product === null) {
                continue;
            }

            $productId = $product->getId();
            $categoryId = $product->getCategory()?->getId();

            if ($productId !== null && in_array($productId, $excludeProducts, true)) {
                continue;
            }

            if ($categoryId !== null && in_array($categoryId, $excludeCategories, true)) {
                continue;
            }

            if ($categories !== [] && $products !== []) {
                $matchesCategory = $categoryId !== null && in_array($categoryId, $categories, true);
                $matchesProduct = $productId !== null && in_array($productId, $products, true);
                $lineMatch = $discount->getLogicOperator() === Discount::LOGIC_OR
                    ? ($matchesCategory || $matchesProduct)
                    : ($matchesCategory && $matchesProduct);
            } elseif ($categories !== []) {
                $lineMatch = $categoryId !== null && in_array($categoryId, $categories, true);
            } elseif ($products !== []) {
                $lineMatch = $productId !== null && in_array($productId, $products, true);
            } else {
                $lineMatch = true;
            }

            if (!$lineMatch) {
                continue;
            }

            $lineSubtotalCents = $this->decimalToIntScale($item->getLineSubtotal() ?? '0.00', 2);
            $lineDiscountCents = $this->decimalToIntScale($item->getLineDiscount() ?? '0.00', 2);
            $lineBaseCents = max(0, $lineSubtotalCents - $lineDiscountCents);

            if ($lineBaseCents <= 0) {
                continue;
            }

            $eligibleItems[] = $item;
            $eligibleBaseCents += $lineBaseCents;
        }

        return [$eligibleItems, $eligibleBaseCents];
    }

    /**
     * @param array<string, mixed> $conditions
     */
    private function matchesConditions(Sale $sale, Discount $discount, array $conditions, string $paymentMethod, int $eligibleBaseCents, int $subtotalCents): bool
    {
        $checks = [];

        if (!empty($conditions['payment_methods'])) {
            $allowed = array_map('strtoupper', (array) $conditions['payment_methods']);
            $checks[] = in_array($paymentMethod, $allowed, true);
        }

        if (!empty($conditions['days_of_week'])) {
            $day = (int) ($sale->getCreatedAt() ?? new \DateTimeImmutable())->format('N');
            $checks[] = in_array($day, array_map('intval', (array) $conditions['days_of_week']), true);
        }

        if (!empty($conditions['hours'])) {
            $time = ($sale->getCreatedAt() ?? new \DateTimeImmutable())->format('H:i');
            $checks[] = $this->isWithinHours($time, (array) $conditions['hours']);
        }

        if (!empty($conditions['min_amount'])) {
            $scope = strtoupper((string) ($conditions['min_amount_scope'] ?? 'ORDER'));
            $minAmount = $this->decimalToIntScale((string) $conditions['min_amount'], 2);
            $base = $scope === 'ELIGIBLE' ? $eligibleBaseCents : $subtotalCents;
            $checks[] = $base >= $minAmount;
        }

        $requiresItemMatch = !empty($conditions['categories']) || !empty($conditions['products']);
        if ($requiresItemMatch) {
            $checks[] = $eligibleBaseCents > 0;
        }

        if ($checks === []) {
            return true;
        }

        if ($discount->getLogicOperator() === Discount::LOGIC_OR) {
            return in_array(true, $checks, true);
        }

        foreach ($checks as $check) {
            if ($check === false) {
                return false;
            }
        }

        return true;
    }

    private function calculateDiscountCents(Discount $discount, int $eligibleBaseCents): int
    {
        if ($discount->getActionType() === Discount::ACTION_FIXED) {
            return max(0, $this->decimalToIntScale($discount->getActionValue() ?? '0.00', 2));
        }

        $percentageBasis = $this->decimalToIntScale($discount->getActionValue() ?? '0.00', 2);
        $raw = $eligibleBaseCents * $percentageBasis;
        $discountCents = intdiv($raw, 10000);
        $remainder = abs($raw % 10000);

        if ($remainder >= 5000) {
            $discountCents += $raw >= 0 ? 1 : -1;
        }

        return $discountCents;
    }

    /**
     * @param array<int, SaleItem> $items
     * @return array<int, int>
     */
    private function distributeDiscount(array $items, int $eligibleBaseCents, int $discountCents): array
    {
        $distribution = [];
        $remaining = $discountCents;
        $count = count($items);

        foreach ($items as $index => $item) {
            $lineSubtotalCents = $this->decimalToIntScale($item->getLineSubtotal() ?? '0.00', 2);
            $lineDiscountCents = $this->decimalToIntScale($item->getLineDiscount() ?? '0.00', 2);
            $lineBaseCents = max(0, $lineSubtotalCents - $lineDiscountCents);

            if ($index === $count - 1) {
                $allocation = $remaining;
            } else {
                $raw = $discountCents * $lineBaseCents;
                $allocation = $eligibleBaseCents > 0 ? intdiv($raw, $eligibleBaseCents) : 0;
                $remaining -= $allocation;
            }

            $distribution[spl_object_id($item)] = $allocation;
        }

        return $distribution;
    }

    /**
     * @param array<string, mixed> $hours
     */
    private function isWithinHours(string $time, array $hours): bool
    {
        $start = $hours['start'] ?? null;
        $end = $hours['end'] ?? null;

        if ($start === null && $end === null) {
            return true;
        }

        $timeMinutes = $this->timeToMinutes($time);
        $startMinutes = $start ? $this->timeToMinutes($start) : null;
        $endMinutes = $end ? $this->timeToMinutes($end) : null;

        if ($startMinutes !== null && $endMinutes !== null) {
            if ($startMinutes <= $endMinutes) {
                return $timeMinutes >= $startMinutes && $timeMinutes <= $endMinutes;
            }

            return $timeMinutes >= $startMinutes || $timeMinutes <= $endMinutes;
        }

        if ($startMinutes !== null) {
            return $timeMinutes >= $startMinutes;
        }

        if ($endMinutes !== null) {
            return $timeMinutes <= $endMinutes;
        }

        return true;
    }

    private function timeToMinutes(string $time): int
    {
        [$hour, $minute] = array_pad(explode(':', $time, 2), 2, '0');

        return ((int) $hour * 60) + (int) $minute;
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

        return $sign * (int) $intString;
    }
}
