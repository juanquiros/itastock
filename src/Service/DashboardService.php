<?php

namespace App\Service;

use App\Entity\Business;
use App\Entity\User;
use App\Repository\CashSessionRepository;
use App\Repository\CustomerAccountMovementRepository;
use App\Repository\CustomerRepository;
use App\Repository\PaymentRepository;
use App\Repository\ProductRepository;
use App\Repository\SaleRepository;

class DashboardService
{
    private const TIMEZONE = 'America/Argentina/Buenos_Aires';
    private const PAYMENT_METHODS = ['CASH', 'TRANSFER', 'CARD', 'ACCOUNT'];

    public function __construct(
        private readonly SaleRepository $saleRepository,
        private readonly PaymentRepository $paymentRepository,
        private readonly ProductRepository $productRepository,
        private readonly CustomerAccountMovementRepository $customerAccountMovementRepository,
        private readonly CustomerRepository $customerRepository,
        private readonly CashSessionRepository $cashSessionRepository,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getSummary(Business $business, User $user): array
    {
        return [
            'generatedAt' => (new \DateTimeImmutable('now', $this->getTimezone()))->format(\DateTimeInterface::ATOM),
            'data' => [
                'kpisToday' => $this->getTodayKpis($business, $user),
                'kpisMonth' => $this->getMonthKpis($business),
                'charts' => $this->getCharts($business),
                'alerts' => $this->getAlerts($business),
                'recent' => $this->getRecent($business),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getTodayKpis(Business $business, User $user): array
    {
        [$from, $to] = $this->getTodayRange();
        $totals = $this->saleRepository->aggregateTotals($business, $from, $to);

        $cashExpected = $this->computeCashExpected($business, $user);
        $count = max(1, $totals['count']);

        return [
            'salesTodayAmount' => $totals['amount'],
            'salesTodayCount' => $totals['count'],
            'avgTicketToday' => $totals['amount'] > 0 ? $totals['amount'] / $count : 0.0,
            'cashExpectedNow' => $cashExpected,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getMonthKpis(Business $business): array
    {
        [$from, $to] = $this->getMonthRange();
        $totals = $this->saleRepository->aggregateTotals($business, $from, $to);
        $paymentTotals = $this->paymentRepository->aggregateTotalsByMethodForRange($business, $from, $to);
        $accountTotal = $paymentTotals['ACCOUNT'] ?? 0.0;
        $percentAccount = $totals['amount'] > 0 ? ($accountTotal / $totals['amount']) * 100 : 0.0;
        $activeCustomers = $this->saleRepository->countActiveCustomers($business, $from, $to);

        return [
            'salesMonthAmount' => $totals['amount'],
            'salesMonthCount' => $totals['count'],
            'accountSalesMonthPercent' => $percentAccount,
            'activeCustomersMonthCount' => $activeCustomers,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getCharts(Business $business): array
    {
        [$todayFrom, $todayTo] = $this->getTodayRange();
        $hourly = $this->saleRepository->aggregateByHour($business, $todayFrom, $todayTo);
        $hourMap = array_column($hourly, 'amount', 'hour');

        $salesByHourToday = [];
        for ($i = 0; $i < 24; $i++) {
            $salesByHourToday[] = [
                'hour' => $i,
                'amount' => (float) ($hourMap[$i] ?? 0.0),
            ];
        }

        [$weekFrom, $weekTo, $dates] = $this->getLast7DaysRange();
        $daily = $this->saleRepository->aggregateByDate($business, $weekFrom, $weekTo);
        $dayMap = array_column($daily, 'amount', 'date');

        $salesLast7Days = [];
        foreach ($dates as $date) {
            $salesLast7Days[] = [
                'date' => $date,
                'amount' => (float) ($dayMap[$date] ?? 0.0),
            ];
        }

        $paymentTotals = $this->paymentRepository->aggregateTotalsByMethodForRange($business, $todayFrom, $todayTo);
        $paymentDistributionToday = [];
        foreach (self::PAYMENT_METHODS as $method) {
            $paymentDistributionToday[] = [
                'method' => $method,
                'amount' => (float) ($paymentTotals[$method] ?? 0.0),
            ];
        }

        return [
            'salesByHourToday' => $salesByHourToday,
            'salesLast7Days' => $salesLast7Days,
            'paymentDistributionToday' => $paymentDistributionToday,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getAlerts(Business $business): array
    {
        $lowStock = $this->productRepository->findLowStockTop($business, 5);
        $debtors = $this->customerAccountMovementRepository->findTopDebtors($business, 5);
        $ids = array_map(static fn (array $row) => (int) $row['customerId'], $debtors);
        $customers = $ids ? $this->customerRepository->findBy(['id' => $ids]) : [];

        $customerById = [];
        foreach ($customers as $customer) {
            $customerById[$customer->getId()] = $customer;
        }

        $topDebtors = [];
        foreach ($debtors as $row) {
            $customer = $customerById[(int) $row['customerId']] ?? null;
            if ($customer === null) {
                continue;
            }

            $topDebtors[] = [
                'customerId' => $customer->getId(),
                'name' => (string) $customer->getName(),
                'phone' => (string) ($customer->getPhone() ?? ''),
                'balance' => (float) $row['balance'],
                'lastActivity' => $row['lastMovement'] ? (new \DateTimeImmutable($row['lastMovement']))->format(\DateTimeInterface::ATOM) : null,
            ];
        }

        return [
            'lowStockTop5' => $lowStock,
            'topDebtorsTop5' => $topDebtors,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getRecent(Business $business): array
    {
        $sales = $this->saleRepository->findRecentSales($business, 10);
        $tz = $this->getTimezone();

        $recentSales = array_map(static function (array $row) use ($tz): array {
            $createdAt = $row['createdAt'] instanceof \DateTimeImmutable ? $row['createdAt'] : new \DateTimeImmutable((string) $row['createdAt'], $tz);

            return [
                'saleId' => (int) $row['saleId'],
                'time' => $createdAt->setTimezone($tz)->format('H:i'),
                'customer' => (string) $row['customerName'],
                'method' => (string) $row['paymentMethod'],
                'total' => (float) $row['total'],
            ];
        }, $sales);

        return [
            'recentSalesTop10' => $recentSales,
        ];
    }

    private function computeCashExpected(Business $business, User $user): ?float
    {
        $session = $this->cashSessionRepository->findOpenForUser($business, $user);

        if ($session === null) {
            return null;
        }

        $from = $session->getOpenedAt() ?? new \DateTimeImmutable('today', $this->getTimezone());
        $to = new \DateTimeImmutable('now', $this->getTimezone());
        $totals = $this->paymentRepository->aggregateTotalsByMethodForRange($business, $from, $to, $user);
        $cash = (float) ($totals['CASH'] ?? 0.0);

        return (float) $session->getInitialCash() + $cash;
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function getTodayRange(): array
    {
        $now = new \DateTimeImmutable('now', $this->getTimezone());
        $start = $now->setTime(0, 0, 0);
        $end = $start->modify('+1 day');

        return [$start, $end];
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function getMonthRange(): array
    {
        $now = new \DateTimeImmutable('now', $this->getTimezone());
        $start = $now->modify('first day of this month')->setTime(0, 0, 0);
        $end = $start->modify('+1 month');

        return [$start, $end];
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable, 2: array<int, string>}
     */
    private function getLast7DaysRange(): array
    {
        $today = new \DateTimeImmutable('today', $this->getTimezone());
        $start = $today->modify('-6 days')->setTime(0, 0, 0);
        $end = $today->modify('+1 day')->setTime(0, 0, 0);

        $dates = [];
        $cursor = $start;
        while ($cursor < $end) {
            $dates[] = $cursor->format('Y-m-d');
            $cursor = $cursor->modify('+1 day');
        }

        return [$start, $end, $dates];
    }

    private function getTimezone(): \DateTimeZone
    {
        return new \DateTimeZone(self::TIMEZONE);
    }
}
