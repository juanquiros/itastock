<?php

namespace App\Service;

use App\DTO\ReportDigest;
use App\Entity\Business;
use App\Entity\CashSession;
use App\Entity\CustomerAccountMovement;
use App\Entity\Product;
use App\Entity\Sale;
use App\Entity\SaleItem;
use App\Entity\StockMovement;
use Doctrine\ORM\EntityManagerInterface;

class ReportDigestBuilder
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function buildDaily(Business $business, \DateTimeImmutable $date): ReportDigest
    {
        $start = $date->setTime(0, 0, 0);
        $end = $date->setTime(23, 59, 59);

        return $this->buildForPeriod($business, $start, $end);
    }

    public function buildWeekly(Business $business, \DateTimeImmutable $start, \DateTimeImmutable $end): ReportDigest
    {
        return $this->buildForPeriod($business, $start, $end);
    }

    public function buildMonthly(Business $business, \DateTimeImmutable $start, \DateTimeImmutable $end): ReportDigest
    {
        return $this->buildForPeriod($business, $start, $end);
    }

    public function buildAnnual(Business $business, \DateTimeImmutable $start, \DateTimeImmutable $end): ReportDigest
    {
        return $this->buildForPeriod($business, $start, $end);
    }

    private function buildForPeriod(Business $business, \DateTimeImmutable $start, \DateTimeImmutable $end): ReportDigest
    {
        $digest = new ReportDigest($business->getName() ?? 'N/D', $start, $end);

        $salesSummary = $this->fetchSalesSummary($business, $start, $end);
        if ($salesSummary === null) {
            $digest->addNote('N/D: ventas');
        } else {
            $digest
                ->setSalesCount($salesSummary['count'])
                ->setSalesTotal($salesSummary['total']);
        }

        $cashSummary = $this->fetchCashSummary($business, $start, $end);
        if ($cashSummary === null) {
            $digest->addNote('N/D: caja');
        } else {
            $digest
                ->setCashOpenCount($cashSummary['openCount'])
                ->setCashCloseCount($cashSummary['closeCount'])
                ->setCashDifferenceTotal($cashSummary['difference']);
        }

        $movementSummary = $this->fetchStockMovements($business, $start, $end);
        if ($movementSummary === null) {
            $digest->addNote('N/D: movimientos de stock');
        } else {
            $digest
                ->setMovementsInTotal($movementSummary['in'])
                ->setMovementsOutTotal($movementSummary['out']);
        }

        $topProducts = $this->fetchTopProducts($business, $start, $end);
        if ($topProducts === null) {
            $digest->addNote('N/D: productos más vendidos');
        } else {
            $digest->setTopProducts($topProducts);
        }

        $debtorsSummary = $this->fetchDebtorsSummary($business, $start, $end);
        if ($debtorsSummary === null) {
            $digest->addNote('N/D: cuentas corrientes');
        } else {
            $digest
                ->setDebtorsCount($debtorsSummary['count'])
                ->setDebtorsTotal($debtorsSummary['total']);
        }

        $lowStock = $this->fetchLowStock($business);
        if ($lowStock === null) {
            $digest->addNote('N/D: stock bajo');
        } else {
            $digest->setLowStock($lowStock);
        }

        return $digest;
    }

    protected function fetchSalesSummary(Business $business, \DateTimeImmutable $start, \DateTimeImmutable $end): ?array
    {
        if (!class_exists(Sale::class)) {
            return null;
        }

        $qb = $this->entityManager->createQueryBuilder();
        $result = $qb
            ->select('COUNT(s.id) as salesCount', 'COALESCE(SUM(s.total), 0) as salesTotal')
            ->from(Sale::class, 's')
            ->where('s.business = :business')
            ->andWhere('s.status = :status')
            ->andWhere('s.createdAt BETWEEN :start AND :end')
            ->setParameter('business', $business)
            ->setParameter('status', Sale::STATUS_CONFIRMED)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getSingleResult();

        return [
            'count' => (int) ($result['salesCount'] ?? 0),
            'total' => (float) ($result['salesTotal'] ?? 0),
        ];
    }

    protected function fetchCashSummary(Business $business, \DateTimeImmutable $start, \DateTimeImmutable $end): ?array
    {
        if (!class_exists(CashSession::class)) {
            return null;
        }

        $qb = $this->entityManager->createQueryBuilder();
        $summary = $qb
            ->select(
                'COUNT(cs.id) as openCount',
                'SUM(CASE WHEN cs.closedAt IS NOT NULL THEN 1 ELSE 0 END) as closeCount',
                'COALESCE(SUM(CASE WHEN cs.closedAt IS NOT NULL AND cs.finalCashCounted IS NOT NULL THEN cs.finalCashCounted - cs.initialCash ELSE 0 END), 0) as cashDifference'
            )
            ->from(CashSession::class, 'cs')
            ->where('cs.business = :business')
            ->andWhere('cs.openedAt BETWEEN :start AND :end')
            ->setParameter('business', $business)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getSingleResult();

        return [
            'openCount' => (int) ($summary['openCount'] ?? 0),
            'closeCount' => (int) ($summary['closeCount'] ?? 0),
            'difference' => (float) ($summary['cashDifference'] ?? 0),
        ];
    }

    protected function fetchStockMovements(Business $business, \DateTimeImmutable $start, \DateTimeImmutable $end): ?array
    {
        if (!class_exists(StockMovement::class)) {
            return null;
        }

        $inTypes = [StockMovement::TYPE_PURCHASE, StockMovement::TYPE_ADJUST];
        $outTypes = [StockMovement::TYPE_SALE, StockMovement::TYPE_SALE_VOID];

        $qb = $this->entityManager->createQueryBuilder();
        $summary = $qb
            ->select(
                'COALESCE(SUM(CASE WHEN sm.type IN (:inTypes) THEN sm.qty ELSE 0 END), 0) as movementsIn',
                'COALESCE(SUM(CASE WHEN sm.type IN (:outTypes) THEN sm.qty ELSE 0 END), 0) as movementsOut'
            )
            ->from(StockMovement::class, 'sm')
            ->join('sm.product', 'p')
            ->where('p.business = :business')
            ->andWhere('sm.createdAt BETWEEN :start AND :end')
            ->setParameter('business', $business)
            ->setParameter('inTypes', $inTypes)
            ->setParameter('outTypes', $outTypes)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getSingleResult();

        return [
            'in' => (float) ($summary['movementsIn'] ?? 0),
            'out' => (float) ($summary['movementsOut'] ?? 0),
        ];
    }

    /**
     * @return array<int, array{name: string, qty: float, total: float}>|null
     */
    protected function fetchTopProducts(Business $business, \DateTimeImmutable $start, \DateTimeImmutable $end): ?array
    {
        if (!class_exists(SaleItem::class)) {
            return null;
        }

        $qb = $this->entityManager->createQueryBuilder();
        $rows = $qb
            ->select(
                'COALESCE(p.name, si.description) as productName',
                'SUM(si.qty) as totalQty',
                'SUM(si.lineTotal) as totalAmount'
            )
            ->from(SaleItem::class, 'si')
            ->join('si.sale', 's')
            ->leftJoin('si.product', 'p')
            ->where('s.business = :business')
            ->andWhere('s.status = :status')
            ->andWhere('s.createdAt BETWEEN :start AND :end')
            ->groupBy('productName')
            ->orderBy('totalAmount', 'DESC')
            ->setMaxResults(10)
            ->setParameter('business', $business)
            ->setParameter('status', Sale::STATUS_CONFIRMED)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getArrayResult();

        return array_map(
            static fn (array $row): array => [
                'name' => (string) ($row['productName'] ?? 'N/D'),
                'qty' => (float) ($row['totalQty'] ?? 0),
                'total' => (float) ($row['totalAmount'] ?? 0),
            ],
            $rows
        );
    }

    protected function fetchDebtorsSummary(Business $business, \DateTimeImmutable $start, \DateTimeImmutable $end): ?array
    {
        if (!class_exists(CustomerAccountMovement::class)) {
            return null;
        }

        $qb = $this->entityManager->createQueryBuilder();
        $rows = $qb
            ->select(
                'IDENTITY(cam.customer) as customerId',
                'SUM(CASE WHEN cam.type = :debit THEN cam.amount ELSE -cam.amount END) as balance'
            )
            ->from(CustomerAccountMovement::class, 'cam')
            ->where('cam.business = :business')
            ->andWhere('cam.createdAt BETWEEN :start AND :end')
            ->groupBy('cam.customer')
            ->setParameter('business', $business)
            ->setParameter('debit', CustomerAccountMovement::TYPE_DEBIT)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getArrayResult();

        $count = 0;
        $total = 0.0;
        foreach ($rows as $row) {
            $balance = (float) ($row['balance'] ?? 0);
            if ($balance > 0) {
                $count++;
                $total += $balance;
            }
        }

        return [
            'count' => $count,
            'total' => $total,
        ];
    }

    /**
     * @return array<int, array{name: string, stock: float, minStock: float}>|null
     */
    protected function fetchLowStock(Business $business): ?array
    {
        if (!class_exists(Product::class)) {
            return null;
        }

        $qb = $this->entityManager->createQueryBuilder();
        $rows = $qb
            ->select('p.name as name', 'p.stock as stock', 'p.stockMin as minStock')
            ->from(Product::class, 'p')
            ->where('p.business = :business')
            ->andWhere('p.stock <= p.stockMin')
            ->orderBy('p.stock', 'ASC')
            ->setMaxResults(10)
            ->setParameter('business', $business)
            ->getQuery()
            ->getArrayResult();

        return array_map(
            static fn (array $row): array => [
                'name' => (string) ($row['name'] ?? 'N/D'),
                'stock' => (float) ($row['stock'] ?? 0),
                'minStock' => (float) ($row['minStock'] ?? 0),
            ],
            $rows
        );
    }
}
