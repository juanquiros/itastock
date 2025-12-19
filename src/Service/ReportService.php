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
            ->leftJoin('s.createdBy', 'u')
            ->leftJoin('s.customer', 'c')
            ->leftJoin('s.payments', 'p')
            ->andWhere('s.business = :business')
            ->andWhere('s.createdAt >= :from')
            ->andWhere('s.createdAt <= :to')
            ->setParameter('business', $business)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
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
    public function getCashSessionSummary(CashSession $session): array
    {
        $business = $session->getBusiness();
        $from = $session->getOpenedAt();
        $to = $session->getClosedAt() ?? new \DateTimeImmutable();

        $totals = $session->isOpen()
            ? $this->paymentRepository->aggregateTotalsByMethod($business, $from, $to)
            : $session->getTotalsByPaymentMethod();

        $cash = (float) ($totals['CASH'] ?? 0);
        $initial = (float) $session->getInitialCash();
        $cashExpected = $initial + $cash;
        $finalCash = $session->getFinalCashCounted() !== null ? (float) $session->getFinalCashCounted() : null;
        $difference = $finalCash !== null ? $finalCash - $cashExpected : null;

        $sales = $this->getSalesForRange($business, $from, $to);

        return [
            'totals' => $totals,
            'cashExpected' => number_format($cashExpected, 2, '.', ''),
            'difference' => $difference !== null ? number_format($difference, 2, '.', '') : null,
            'finalCash' => $finalCash !== null ? number_format($finalCash, 2, '.', '') : null,
            'sales' => $sales,
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
            ->setParameter('business', $business)
            ->orderBy('(p.stock - p.stockMin)', 'ASC')
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
     * @return array{balance:string, totalDebit:string, totalCredit:string, movements: array<int, \App\Entity\CustomerAccountMovement>}
     */
    public function getCustomerAccountData(Customer $customer, ?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null): array
    {
        $movements = $this->customerAccountMovementRepository->findForCustomer($customer, $from, $to, null);
        $balance = (float) $this->customerAccountMovementRepository->getBalance($customer);
        $debit = 0.0;
        $credit = 0.0;

        foreach ($movements as $movement) {
            if ($movement->getType() === 'DEBIT') {
                $debit += (float) $movement->getAmount();
            } else {
                $credit += (float) $movement->getAmount();
            }
        }

        return [
            'balance' => number_format($balance, 2, '.', ''),
            'totalDebit' => number_format($debit, 2, '.', ''),
            'totalCredit' => number_format($credit, 2, '.', ''),
            'movements' => $movements,
        ];
    }
}
