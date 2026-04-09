<?php

namespace App\Tests\Service;

use App\Entity\Business;
use App\Entity\CashSession;
use App\Repository\CustomerAccountMovementRepository;
use App\Repository\CustomerRepository;
use App\Repository\PaymentRepository;
use App\Repository\ProductRepository;
use App\Repository\SaleRepository;
use App\Repository\SupplierPaymentRepository;
use App\Service\ReportService;
use PHPUnit\Framework\TestCase;

class ReportServiceSupplierPaymentTest extends TestCase
{
    public function testCashExpectedSubtractsSupplierCashEgress(): void
    {
        $session = new CashSession();
        $session->setBusiness(new Business());
        $session->setInitialCash('1000.00');
        $session->setOpenedAt(new \DateTimeImmutable('2026-04-09 09:00:00'));
        $session->setClosedAt(new \DateTimeImmutable('2026-04-09 18:00:00'));

        $paymentRepository = $this->createMock(PaymentRepository::class);
        $paymentRepository->method('aggregateTotalsByMethod')->willReturn([
            'CASH' => '700.00',
            'TRANSFER' => '200.00',
        ]);

        $supplierPaymentRepository = $this->createMock(SupplierPaymentRepository::class);
        $supplierPaymentRepository->method('aggregateTotalsByMethod')->willReturn([
            'CASH' => '250.00',
            'TRANSFER' => '500.00',
        ]);

        $saleRepository = $this->createMock(SaleRepository::class);

        $service = new class(
            $saleRepository,
            $paymentRepository,
            $this->createMock(ProductRepository::class),
            $this->createMock(CustomerAccountMovementRepository::class),
            $this->createMock(CustomerRepository::class),
            $supplierPaymentRepository,
        ) extends ReportService {
            public function getSalesForRange(Business $business, \DateTimeImmutable $from, \DateTimeImmutable $to, array $filters = []): array
            {
                return [];
            }
        };

        $summary = $service->getCashSessionSummary($session, false);

        self::assertSame('1450.00', $summary['cashExpected']);
        self::assertSame('450.00', $summary['netByMethod']['CASH']);
        self::assertSame('-300.00', $summary['netByMethod']['TRANSFER']);
    }
}
