<?php

namespace App\Service;

use App\Entity\Business;
use App\Entity\CashSession;
use App\Entity\Customer;
use App\Repository\CustomerAccountMovementRepository;
use App\Repository\CustomerRepository;
use App\Repository\PaymentRepository;
use App\Repository\ProductRepository;
use App\Repository\SaleRepository;

class ReportService
{
    public function __construct(
        private readonly SaleRepository $saleRepository,
        private readonly PaymentRepository $paymentRepository,
        private readonly ProductRepository $productRepository,
        private readonly CustomerAccountMovementRepository $customerAccountMovementRepository,
        private readonly CustomerRepository $customerRepository,
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function getSalesForRange(Business $business, \DateTimeImmutable $from, \DateTimeImmutable $to, array $filters = []): array
    {
        $qb = $this->saleRepository->createQueryBuilder('s')
            ->select('s.id AS saleId')
            ->addSelect('s.createdAt AS createdAt')
            ->addSelect('s.total AS total')
            ->addSelect('u.email AS sellerEmail')
            ->addSelect('COALESCE(c.name, :defaultCustomer) AS customerName')
            ->addSelect('MIN(p.method) AS paymentMethod')
            ->addSelect('COUNT(DISTINCT items.id) AS itemsCount')
            ->leftJoin('s.createdBy', 'u')
            ->leftJoin('s.customer', 'c')
            ->leftJoin('s.payments', 'p')
            ->leftJoin('s.items', 'items')
            ->andWhere('s.business = :business')
            ->andWhere('s.createdAt >= :from')
            ->andWhere('s.createdAt <= :to')
            ->andWhere('s.status = :status')
            ->setParameter('business', $business)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('status', \App\Entity\Sale::STATUS_CONFIRMED)
            ->setParameter('defaultCustomer', 'Consumidor final')
            ->groupBy('s.id, u.email, c.name, s.total, s.createdAt')
            ->orderBy('s.createdAt', 'ASC');

        if (!empty($filters['seller'])) {
            $qb->andWhere('u.email = :seller')->setParameter('seller', $filters['seller']);
        }

        if (!empty($filters['method'])) {
            $qb->andWhere('p.method = :method')->setParameter('method', $filters['method']);
        }

        if (!empty($filters['customerId'])) {
            $qb->andWhere('c.id = :customerId')->setParameter('customerId', $filters['customerId']);
        }

        return $qb->getQuery()->getArrayResult();
    }

    /**
     * @return array<string, mixed>
     */
    public function getCashSessionSummary(CashSession $session, bool $includeSaleItems = false): array
    {
        $business = $session->getBusiness();
        $from = $session->getOpenedAt();
        $to = $session->getClosedAt() ?? new \DateTimeImmutable();
        if ($to < $from) {
            $to = new \DateTimeImmutable();
        }

        $storedTotals = $session->getTotalsByPaymentMethod();
        $totals = $session->isOpen() || $storedTotals === []
            ? $this->paymentRepository->aggregateTotalsByMethod($business, $from, $to)
            : $storedTotals;

        $cash = (float) ($totals['CASH'] ?? 0);
        $initial = (float) $session->getInitialCash();
        $cashExpected = $initial + $cash;
        $finalCash = $session->getFinalCashCounted() !== null ? (float) $session->getFinalCashCounted() : null;
        $difference = $finalCash !== null ? $finalCash - $cashExpected : null;

        $sales = $this->getSalesForRange($business, $from, $to);
        $saleDetails = [];

        if ($includeSaleItems) {
            $saleIds = array_map(static fn (array $row) => (int) $row['saleId'], $sales);
            $salesWithItems = $saleIds !== [] ? $this->saleRepository->findWithItemsByIds($business, $saleIds) : [];
            foreach ($salesWithItems as $sale) {
                $saleDetails[$sale->getId()] = array_map(static fn ($item) => [
                    'description' => $item->getDescription(),
                    'qty' => $item->getQty(),
                    'unitPrice' => number_format((float) $item->getUnitPrice(), 2, '.', ''),
                    'lineTotal' => number_format((float) $item->getLineTotal(), 2, '.', ''),
                ], $sale->getItems()->toArray());
            }
        }

        return [
            'totals' => $totals,
            'cashExpected' => number_format($cashExpected, 2, '.', ''),
            'difference' => $difference !== null ? number_format($difference, 2, '.', '') : null,
            'finalCash' => $finalCash !== null ? number_format($finalCash, 2, '.', '') : null,
            'sales' => $sales,
            'saleDetails' => $saleDetails,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getLowStockProducts(Business $business): array
    {
        return $this->productRepository->createQueryBuilder('p')
            ->andWhere('p.business = :business')
            ->andWhere('p.stock <= p.stockMin')
            ->addSelect('p.stock - p.stockMin AS HIDDEN deficit')
            ->setParameter('business', $business)
            ->orderBy('deficit', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getDebtors(Business $business, float $minBalance): array
    {
        $rows = $this->customerAccountMovementRepository->findDebtors($business, $minBalance);
        $ids = array_map(static fn (array $row) => (int) $row['customerId'], $rows);
        $customers = $ids ? $this->customerRepository->findBy(['id' => $ids]) : [];

        $map = [];
        foreach ($customers as $customer) {
            $map[$customer->getId()] = $customer;
        }

        $report = [];
        foreach ($rows as $row) {
            $customer = $map[$row['customerId']] ?? null;
            if ($customer === null) {
                continue;
            }

            $report[] = [
                'customer' => $customer,
                'balance' => (float) $row['balance'],
                'lastMovement' => $row['lastMovement'] ? new \DateTimeImmutable($row['lastMovement']) : null,
            ];
        }

        usort($report, static fn ($a, $b) => $b['balance'] <=> $a['balance']);

        return $report;
    }

    /**
     * @return array{summary: array{salesWithDiscount: int, totalDiscounted: float}, ranking: array<int, array{name: string, total: float}>, byPayment: array<int, array{method: ?string, salesCount: int, totalDiscount: float}>, sales: array<int, array<string, mixed>>}
     */
    public function getDiscountImpact(Business $business, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $connection = $this->saleRepository->getEntityManager()->getConnection();
        $params = [
            'businessId' => $business->getId(),
            'from' => $from->format('Y-m-d 00:00:00'),
            'to' => $to->format('Y-m-d 23:59:59'),
        ];

        $summaryRow = $connection->fetchAssociative(
            <<<SQL
                SELECT COUNT(DISTINCT s.id) AS salesCount, COALESCE(SUM(sd.applied_amount), 0) AS totalDiscount
                FROM sale_discounts sd
                INNER JOIN sales s ON s.id = sd.sale_id
                WHERE s.business_id = :businessId
                  AND s.created_at >= :from
                  AND s.created_at <= :to
                  AND s.status = 'CONFIRMED'
            SQL,
            $params
        ) ?: ['salesCount' => 0, 'totalDiscount' => 0];

        $rankingRows = $connection->fetchAllAssociative(
            <<<SQL
                SELECT sd.discount_name AS name, COALESCE(SUM(sd.applied_amount), 0) AS total
                FROM sale_discounts sd
                INNER JOIN sales s ON s.id = sd.sale_id
                WHERE s.business_id = :businessId
                  AND s.created_at >= :from
                  AND s.created_at <= :to
                  AND s.status = 'CONFIRMED'
                GROUP BY sd.discount_name
                ORDER BY total DESC
            SQL,
            $params
        );

        $paymentRows = $connection->fetchAllAssociative(
            <<<SQL
                SELECT MIN(p.method) AS method,
                       COUNT(DISTINCT s.id) AS salesCount,
                       COALESCE(SUM(s.discount_total), 0) AS totalDiscount
                FROM sales s
                LEFT JOIN payments p ON p.sale_id = s.id
                WHERE s.business_id = :businessId
                  AND s.created_at >= :from
                  AND s.created_at <= :to
                  AND s.status = 'CONFIRMED'
                  AND s.discount_total > 0
                GROUP BY method
                ORDER BY totalDiscount DESC
            SQL,
            $params
        );

        $salesRows = $connection->fetchAllAssociative(
            <<<SQL
                SELECT s.id AS saleId,
                       s.created_at AS createdAt,
                       s.subtotal AS subtotal,
                       s.discount_total AS discountTotal,
                       s.total AS total,
                       MIN(p.method) AS paymentMethod
                FROM sales s
                LEFT JOIN payments p ON p.sale_id = s.id
                WHERE s.business_id = :businessId
                  AND s.created_at >= :from
                  AND s.created_at <= :to
                  AND s.status = 'CONFIRMED'
                  AND s.discount_total > 0
                GROUP BY s.id, s.created_at, s.subtotal, s.discount_total, s.total
                ORDER BY s.created_at DESC
            SQL,
            $params
        );

        return [
            'summary' => [
                'salesWithDiscount' => (int) ($summaryRow['salesCount'] ?? 0),
                'totalDiscounted' => (float) ($summaryRow['totalDiscount'] ?? 0),
            ],
            'ranking' => array_map(static fn (array $row) => [
                'name' => (string) ($row['name'] ?? 'Sin nombre'),
                'total' => (float) ($row['total'] ?? 0),
            ], $rankingRows),
            'byPayment' => array_map(static fn (array $row) => [
                'method' => $row['method'] !== null ? (string) $row['method'] : null,
                'salesCount' => (int) ($row['salesCount'] ?? 0),
                'totalDiscount' => (float) ($row['totalDiscount'] ?? 0),
            ], $paymentRows),
            'sales' => array_map(static fn (array $row) => [
                'saleId' => (int) $row['saleId'],
                'createdAt' => new \DateTimeImmutable($row['createdAt']),
                'subtotal' => (float) $row['subtotal'],
                'discountTotal' => (float) $row['discountTotal'],
                'total' => (float) $row['total'],
                'paymentMethod' => $row['paymentMethod'] !== null ? (string) $row['paymentMethod'] : null,
            ], $salesRows),
        ];
    }

    /**
     * @return array{balance:string, totalDebit:string, totalCredit:string, movements: array<int, \App\Entity\CustomerAccountMovement>, saleDetails: array<int, array<int, array<string, string>>>}
     */
    public function getCustomerAccountData(Customer $customer, ?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null, bool $includeSaleItems = false): array
    {
        $movements = $this->customerAccountMovementRepository->findForCustomer($customer, $from, $to, null);
        $balance = (float) $this->customerAccountMovementRepository->getBalance($customer);
        $debit = 0.0;
        $credit = 0.0;
        $saleDetails = [];

        foreach ($movements as $movement) {
            if ($movement->getType() === 'DEBIT') {
                $debit += (float) $movement->getAmount();
            } else {
                $credit += (float) $movement->getAmount();
            }
        }

        if ($includeSaleItems) {
            $saleIds = [];
            foreach ($movements as $movement) {
                if (in_array($movement->getReferenceType(), ['SALE', 'SALE_VOID'], true) && $movement->getReferenceId()) {
                    $saleIds[] = $movement->getReferenceId();
                }
            }

            $saleIds = array_values(array_unique($saleIds));
            $sales = $saleIds !== [] ? $this->saleRepository->findWithItemsByIds($customer->getBusiness(), $saleIds) : [];

            foreach ($sales as $sale) {
                $saleDetails[$sale->getId()] = array_map(static fn ($item) => [
                    'description' => $item->getDescription(),
                    'qty' => $item->getQty(),
                    'unitPrice' => number_format((float) $item->getUnitPrice(), 2, '.', ''),
                    'lineTotal' => number_format((float) $item->getLineTotal(), 2, '.', ''),
                ], $sale->getItems()->toArray());
            }
        }

        return [
            'balance' => number_format($balance, 2, '.', ''),
            'totalDebit' => number_format($debit, 2, '.', ''),
            'totalCredit' => number_format($credit, 2, '.', ''),
            'movements' => $movements,
            'saleDetails' => $saleDetails,
        ];
    }
}
