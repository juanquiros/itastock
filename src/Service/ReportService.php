<?php

namespace App\Service;

use App\Entity\ArcaInvoice;
use App\Entity\Business;
use App\Entity\BusinessUser;
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
            ->addSelect('u.id AS sellerId')
            ->addSelect('COALESCE(c.name, :defaultCustomer) AS customerName')
            ->addSelect('MIN(p.method) AS paymentMethod')
            ->addSelect('COUNT(DISTINCT items.id) AS itemsCount')
            ->addSelect('MAX(ai.id) AS arcaInvoiceId')
            ->addSelect('MAX(ai.status) AS arcaInvoiceStatus')
            ->addSelect('MAX(ai.arcaPosNumber) AS arcaInvoicePosNumber')
            ->addSelect('MAX(ai.cae) AS arcaInvoiceCae')
            ->addSelect('MAX(ai.cbteNumero) AS arcaInvoiceNumber')
            ->addSelect('MAX(bu.arcaEnabledForThisCashier) AS arcaEnabledForThisCashier')
            ->addSelect('MAX(bu.arcaMode) AS arcaMode')
            ->addSelect('MAX(bu.arcaPosNumber) AS arcaPosNumber')
            ->leftJoin('s.createdBy', 'u')
            ->leftJoin('s.customer', 'c')
            ->leftJoin('s.payments', 'p')
            ->leftJoin('s.items', 'items')
            ->leftJoin(ArcaInvoice::class, 'ai', 'WITH', 'ai.sale = s AND ai.business = :business')
            ->leftJoin(BusinessUser::class, 'bu', 'WITH', 'bu.user = u AND bu.business = :business AND bu.isActive = true')
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

    /**
     * @return array{summary: array{net: float, iva: float, total: float}, invoices: array<int, array<string, mixed>>}
     */
    public function getPurchaseVatReport(Business $business, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $connection = $this->saleRepository->getEntityManager()->getConnection();
        $params = [
            'businessId' => $business->getId(),
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
        ];

        $rows = $connection->fetchAllAssociative(
            <<<SQL
                SELECT pi.id,
                       s.name AS supplierName,
                       pi.invoice_type AS invoiceType,
                       pi.point_of_sale AS pointOfSale,
                       pi.invoice_number AS invoiceNumber,
                       pi.invoice_date AS invoiceDate,
                       pi.net_amount AS netAmount,
                       pi.iva_rate AS ivaRate,
                       pi.iva_amount AS ivaAmount,
                       pi.total_amount AS totalAmount
                FROM purchase_invoices pi
                INNER JOIN suppliers s ON s.id = pi.supplier_id
                WHERE pi.business_id = :businessId
                  AND pi.status = 'CONFIRMED'
                  AND pi.invoice_date >= :from
                  AND pi.invoice_date <= :to
                ORDER BY pi.invoice_date ASC, pi.id ASC
            SQL,
            $params
        );

        $summary = [
            'net' => 0.0,
            'iva' => 0.0,
            'total' => 0.0,
        ];

        $invoices = array_map(static function (array $row) use (&$summary): array {
            $net = (float) $row['netAmount'];
            $iva = (float) $row['ivaAmount'];
            $total = (float) $row['totalAmount'];

            $summary['net'] += $net;
            $summary['iva'] += $iva;
            $summary['total'] += $total;

            return [
                'id' => (int) $row['id'],
                'supplierName' => (string) $row['supplierName'],
                'invoiceType' => (string) $row['invoiceType'],
                'pointOfSale' => $row['pointOfSale'] !== null ? (string) $row['pointOfSale'] : null,
                'invoiceNumber' => (string) $row['invoiceNumber'],
                'invoiceDate' => new \DateTimeImmutable($row['invoiceDate']),
                'netAmount' => $net,
                'ivaRate' => (float) $row['ivaRate'],
                'ivaAmount' => $iva,
                'totalAmount' => $total,
            ];
        }, $rows);

        return [
            'summary' => $summary,
            'invoices' => $invoices,
        ];
    }

    /**
     * @return array<int, array{supplierName: string, total: float, invoicesCount: int}>
     */
    public function getPurchaseTotalsBySupplier(Business $business, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $connection = $this->saleRepository->getEntityManager()->getConnection();
        $params = [
            'businessId' => $business->getId(),
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
        ];

        $rows = $connection->fetchAllAssociative(
            <<<SQL
                SELECT s.name AS supplierName,
                       COUNT(pi.id) AS invoicesCount,
                       COALESCE(SUM(pi.total_amount), 0) AS totalAmount
                FROM purchase_invoices pi
                INNER JOIN suppliers s ON s.id = pi.supplier_id
                WHERE pi.business_id = :businessId
                  AND pi.status = 'CONFIRMED'
                  AND pi.invoice_date >= :from
                  AND pi.invoice_date <= :to
                GROUP BY s.name
                ORDER BY totalAmount DESC
            SQL,
            $params
        );

        return array_map(static fn (array $row) => [
            'supplierName' => (string) $row['supplierName'],
            'total' => (float) $row['totalAmount'],
            'invoicesCount' => (int) $row['invoicesCount'],
        ], $rows);
    }

    /**
     * @return array{summary: array{net: float, iva: float, total: float}, invoices: array<int, array<string, mixed>>}
     */
    public function getSalesVatReport(Business $business, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $connection = $this->saleRepository->getEntityManager()->getConnection();
        $params = [
            'businessId' => $business->getId(),
            'from' => $from->format('Y-m-d 00:00:00'),
            'to' => $to->format('Y-m-d 23:59:59'),
        ];

        $invoiceRows = $connection->fetchAllAssociative(
            <<<SQL
                SELECT ai.id,
                       COALESCE(ai.issued_at, ai.created_at) AS issuedAt,
                       ai.cbte_tipo AS cbteTipo,
                       ai.arca_pos_number AS posNumber,
                       ai.cbte_numero AS cbteNumero,
                       ai.cae AS cae,
                       COALESCE(rc.name, c.name, 'Consumidor final') AS customerName,
                       COALESCE(rc.document_number, c.document_number, '') AS customerDocument,
                       ai.net_amount AS netAmount,
                       ai.vat_amount AS vatAmount,
                       ai.total_amount AS totalAmount
                FROM arca_invoices ai
                LEFT JOIN sales s ON s.id = ai.sale_id
                LEFT JOIN customers c ON c.id = s.customer_id
                LEFT JOIN customers rc ON rc.id = ai.receiver_customer_id
                WHERE ai.business_id = :businessId
                  AND ai.status = 'AUTHORIZED'
                  AND COALESCE(ai.issued_at, ai.created_at) >= :from
                  AND COALESCE(ai.issued_at, ai.created_at) <= :to
                ORDER BY issuedAt ASC, ai.id ASC
            SQL,
            $params
        );

        $creditNoteRows = $connection->fetchAllAssociative(
            <<<SQL
                SELECT acn.id,
                       COALESCE(acn.issued_at, acn.created_at) AS issuedAt,
                       acn.cbte_tipo AS cbteTipo,
                       acn.arca_pos_number AS posNumber,
                       acn.cbte_numero AS cbteNumero,
                       acn.cae AS cae,
                       COALESCE(rc.name, c.name, 'Consumidor final') AS customerName,
                       COALESCE(rc.document_number, c.document_number, '') AS customerDocument,
                       acn.net_amount AS netAmount,
                       acn.vat_amount AS vatAmount,
                       acn.total_amount AS totalAmount
                FROM arca_credit_notes acn
                LEFT JOIN arca_invoices ai ON ai.id = acn.related_invoice_id
                LEFT JOIN sales s ON s.id = acn.sale_id
                LEFT JOIN customers c ON c.id = s.customer_id
                LEFT JOIN customers rc ON rc.id = ai.receiver_customer_id
                WHERE acn.business_id = :businessId
                  AND acn.status = 'AUTHORIZED'
                  AND COALESCE(acn.issued_at, acn.created_at) >= :from
                  AND COALESCE(acn.issued_at, acn.created_at) <= :to
                ORDER BY issuedAt ASC, acn.id ASC
            SQL,
            $params
        );

        $summary = [
            'net' => 0.0,
            'iva' => 0.0,
            'total' => 0.0,
        ];

        $normalize = static function (array $row, bool $isCredit) use (&$summary): array {
            $net = (float) $row['netAmount'];
            $iva = (float) $row['vatAmount'];
            $total = (float) $row['totalAmount'];
            $sign = $isCredit ? -1 : 1;

            $summary['net'] += $net * $sign;
            $summary['iva'] += $iva * $sign;
            $summary['total'] += $total * $sign;

            return [
                'id' => (int) $row['id'],
                'issuedAt' => new \DateTimeImmutable($row['issuedAt']),
                'cbteTipo' => (string) $row['cbteTipo'],
                'posNumber' => $row['posNumber'] !== null ? (int) $row['posNumber'] : null,
                'cbteNumero' => $row['cbteNumero'] !== null ? (int) $row['cbteNumero'] : null,
                'cae' => $row['cae'] !== null ? (string) $row['cae'] : null,
                'customerName' => (string) $row['customerName'],
                'customerDocument' => (string) $row['customerDocument'],
                'netAmount' => $net * $sign,
                'vatAmount' => $iva * $sign,
                'totalAmount' => $total * $sign,
                'isCredit' => $isCredit,
            ];
        };

        $invoices = array_map(static fn (array $row) => $normalize($row, false), $invoiceRows);
        $creditNotes = array_map(static fn (array $row) => $normalize($row, true), $creditNoteRows);

        $rows = array_merge($invoices, $creditNotes);
        usort($rows, static fn ($a, $b) => $a['issuedAt'] <=> $b['issuedAt']);

        return [
            'summary' => $summary,
            'invoices' => $rows,
        ];
    }

    /**
     * @return array{summary: array{totalDebits: float, totalCredits: float}, rows: array<int, array<string, mixed>>}
     */
    public function getLedgerReport(Business $business, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $connection = $this->saleRepository->getEntityManager()->getConnection();
        $params = [
            'businessId' => $business->getId(),
            'from' => $from->format('Y-m-d 00:00:00'),
            'to' => $to->format('Y-m-d 23:59:59'),
        ];

        $saleRows = $connection->fetchAllAssociative(
            <<<SQL
                SELECT s.id AS saleId,
                       s.created_at AS createdAt,
                       s.total AS totalAmount,
                       MIN(p.method) AS paymentMethod
                FROM sales s
                LEFT JOIN payments p ON p.sale_id = s.id
                WHERE s.business_id = :businessId
                  AND s.status = 'CONFIRMED'
                  AND s.created_at >= :from
                  AND s.created_at <= :to
                GROUP BY s.id, s.created_at, s.total
                ORDER BY s.created_at ASC
            SQL,
            $params
        );

        $creditNoteRows = $connection->fetchAllAssociative(
            <<<SQL
                SELECT acn.id AS creditNoteId,
                       COALESCE(acn.issued_at, acn.created_at) AS issuedAt,
                       acn.total_amount AS totalAmount,
                       MIN(p.method) AS paymentMethod,
                       ai.cbte_numero AS invoiceNumber
                FROM arca_credit_notes acn
                LEFT JOIN sales s ON s.id = acn.sale_id
                LEFT JOIN payments p ON p.sale_id = s.id
                LEFT JOIN arca_invoices ai ON ai.id = acn.related_invoice_id
                WHERE acn.business_id = :businessId
                  AND acn.status = 'AUTHORIZED'
                  AND COALESCE(acn.issued_at, acn.created_at) >= :from
                  AND COALESCE(acn.issued_at, acn.created_at) <= :to
                GROUP BY acn.id, issuedAt, acn.total_amount, ai.cbte_numero
                ORDER BY issuedAt ASC
            SQL,
            $params
        );

        $purchaseRows = $connection->fetchAllAssociative(
            <<<SQL
                SELECT pi.id AS purchaseId,
                       pi.invoice_date AS invoiceDate,
                       pi.net_amount AS netAmount,
                       pi.iva_amount AS ivaAmount,
                       pi.total_amount AS totalAmount
                FROM purchase_invoices pi
                WHERE pi.business_id = :businessId
                  AND pi.status = 'CONFIRMED'
                  AND pi.invoice_date >= :fromDate
                  AND pi.invoice_date <= :toDate
                ORDER BY pi.invoice_date ASC
            SQL,
            [
                'businessId' => $business->getId(),
                'fromDate' => $from->format('Y-m-d'),
                'toDate' => $to->format('Y-m-d'),
            ]
        );

        $rows = [];
        $summary = [
            'totalDebits' => 0.0,
            'totalCredits' => 0.0,
        ];

        foreach ($saleRows as $row) {
            $method = (string) ($row['paymentMethod'] ?? 'CASH');
            $debitAccount = $this->resolvePaymentAccount($method);
            $amount = (float) $row['totalAmount'];
            $rows[] = [
                'date' => new \DateTimeImmutable($row['createdAt']),
                'type' => 'VENTA',
                'reference' => sprintf('Venta #%d', (int) $row['saleId']),
                'debitAccount' => $debitAccount,
                'creditAccount' => 'Ventas',
                'amount' => $amount,
                'note' => '',
            ];
            $summary['totalDebits'] += $amount;
            $summary['totalCredits'] += $amount;
        }

        foreach ($creditNoteRows as $row) {
            $method = (string) ($row['paymentMethod'] ?? 'CASH');
            $creditAccount = $this->resolvePaymentAccount($method);
            $amount = -1 * (float) $row['totalAmount'];
            $invoiceNumber = $row['invoiceNumber'] !== null ? (int) $row['invoiceNumber'] : null;
            $rows[] = [
                'date' => new \DateTimeImmutable($row['issuedAt']),
                'type' => 'NC',
                'reference' => sprintf('NC #%d', (int) $row['creditNoteId']),
                'debitAccount' => 'Ventas',
                'creditAccount' => $creditAccount,
                'amount' => $amount,
                'note' => $invoiceNumber ? sprintf('NC asociada a factura %d', $invoiceNumber) : 'NC asociada a factura',
            ];
            $summary['totalDebits'] += $amount;
            $summary['totalCredits'] += $amount;
        }

        foreach ($purchaseRows as $row) {
            $invoiceDate = new \DateTimeImmutable($row['invoiceDate']);
            $net = (float) $row['netAmount'];
            $iva = (float) $row['ivaAmount'];
            $total = (float) $row['totalAmount'];
            $purchaseId = (int) $row['purchaseId'];

            if ($net > 0) {
                $rows[] = [
                    'date' => $invoiceDate,
                    'type' => 'COMPRA',
                    'reference' => sprintf('Compra #%d', $purchaseId),
                    'debitAccount' => 'Compras',
                    'creditAccount' => 'Proveedores',
                    'amount' => $net,
                    'note' => 'Neto compra',
                ];
                $summary['totalDebits'] += $net;
                $summary['totalCredits'] += $net;
            }

            if ($iva > 0) {
                $rows[] = [
                    'date' => $invoiceDate,
                    'type' => 'COMPRA',
                    'reference' => sprintf('Compra #%d', $purchaseId),
                    'debitAccount' => 'IVA Crédito',
                    'creditAccount' => 'Proveedores',
                    'amount' => $iva,
                    'note' => 'IVA compra',
                ];
                $summary['totalDebits'] += $iva;
                $summary['totalCredits'] += $iva;
            }

            if ($net <= 0 && $iva <= 0) {
                $rows[] = [
                    'date' => $invoiceDate,
                    'type' => 'COMPRA',
                    'reference' => sprintf('Compra #%d', $purchaseId),
                    'debitAccount' => 'Compras',
                    'creditAccount' => 'Proveedores',
                    'amount' => $total,
                    'note' => 'Compra total',
                ];
                $summary['totalDebits'] += $total;
                $summary['totalCredits'] += $total;
            }
        }

        usort($rows, static fn ($a, $b) => $a['date'] <=> $b['date']);

        return [
            'summary' => $summary,
            'rows' => $rows,
        ];
    }

    private function resolvePaymentAccount(string $method): string
    {
        return match ($method) {
            'CASH' => 'Caja',
            'TRANSFER' => 'Banco',
            'CARD' => 'Tarjetas',
            'ACCOUNT' => 'CtaCte Clientes',
            default => 'Caja',
        };
    }
}
