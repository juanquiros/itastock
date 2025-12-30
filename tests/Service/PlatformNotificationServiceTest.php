<?php

namespace App\Tests\Service;

use App\Service\PlatformNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;

class PlatformNotificationServiceTest extends TestCase
{
    public function testDigestContextIsAggregatedOnly(): void
    {
        $service = new class(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(MailerInterface::class),
            ''
        ) extends PlatformNotificationService {
            protected function countSubscriptionsByPeriod(\DateTimeImmutable $start, \DateTimeImmutable $end): int
            {
                return 3;
            }

            protected function countSubscriptionsByStatusInPeriod(string $status, \DateTimeImmutable $start, \DateTimeImmutable $end): int
            {
                return 1;
            }

            protected function countSubscriptionsByStatuses(array $statuses): int
            {
                return 2;
            }

            protected function countSubscriptionsByStatus(string $status): int
            {
                return 5;
            }

            protected function countLeadsByPeriod(\DateTimeImmutable $start, \DateTimeImmutable $end): int
            {
                return 4;
            }

            protected function countBusinessesByPeriod(\DateTimeImmutable $start, \DateTimeImmutable $end): int
            {
                return 2;
            }

            protected function countTrialsActiveAt(\DateTimeImmutable $now): int
            {
                return 6;
            }

            protected function countTrialsExpiringSoon(\DateTimeImmutable $now): int
            {
                return 1;
            }

            protected function countTrialsExpiredBefore(\DateTimeImmutable $now): int
            {
                return 0;
            }

            protected function calculateEstimatedMrr(): ?float
            {
                return 1200.0;
            }

            public function buildDigestPublic(\DateTimeImmutable $start, \DateTimeImmutable $end): array
            {
                return $this->buildDigestContext($start, $end);
            }
        };

        $context = $service->buildDigestPublic(new \DateTimeImmutable('2024-01-01'), new \DateTimeImmutable('2024-01-07'));

        self::assertArrayHasKey('newSubscriptions', $context);
        self::assertArrayHasKey('canceledSubscriptions', $context);
        self::assertArrayHasKey('demoRequests', $context);
        self::assertArrayNotHasKey('totalsByBusiness', $context);
        self::assertArrayNotHasKey('salesByBusiness', $context);
        self::assertArrayNotHasKey('cashByBusiness', $context);
    }
}
