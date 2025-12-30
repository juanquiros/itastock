<?php

namespace App\Tests\Service;

use App\DTO\ReportDigest;
use App\Entity\Business;
use App\Service\ReportDigestBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class ReportDigestBuilderTest extends TestCase
{
    public function testBuildDailyHandlesEmptyData(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $builder = new class($entityManager) extends ReportDigestBuilder {
            protected function fetchSalesSummary(Business $business, \DateTimeImmutable $start, \DateTimeImmutable $end): ?array
            {
                return ['count' => 0, 'total' => 0.0];
            }

            protected function fetchCashSummary(Business $business, \DateTimeImmutable $start, \DateTimeImmutable $end): ?array
            {
                return ['openCount' => 0, 'closeCount' => 0, 'difference' => 0.0];
            }

            protected function fetchStockMovements(Business $business, \DateTimeImmutable $start, \DateTimeImmutable $end): ?array
            {
                return ['in' => 0.0, 'out' => 0.0];
            }

            protected function fetchTopProducts(Business $business, \DateTimeImmutable $start, \DateTimeImmutable $end): ?array
            {
                return [];
            }

            protected function fetchDebtorsSummary(Business $business, \DateTimeImmutable $start, \DateTimeImmutable $end): ?array
            {
                return ['count' => 0, 'total' => 0.0];
            }

            protected function fetchLowStock(Business $business): ?array
            {
                return [];
            }
        };

        $business = new Business();
        $business->setName('Demo');

        $digest = $builder->buildDaily($business, new \DateTimeImmutable('2024-01-01'));

        self::assertInstanceOf(ReportDigest::class, $digest);
        self::assertSame('Demo', $digest->getBusinessName());
        self::assertSame(0, $digest->getSalesCount());
        self::assertSame(0.0, $digest->getSalesTotal());
    }

    public function testBuildAddsNotesWhenSectionMissing(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $builder = new class($entityManager) extends ReportDigestBuilder {
            protected function fetchSalesSummary(Business $business, \DateTimeImmutable $start, \DateTimeImmutable $end): ?array
            {
                return null;
            }
        };

        $business = new Business();
        $business->setName('Demo');

        $digest = $builder->buildDaily($business, new \DateTimeImmutable('2024-01-01'));

        self::assertContains('N/D: ventas', $digest->getNotes());
    }
}
