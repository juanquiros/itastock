<?php

namespace App\Tests\Service;

use App\Entity\Business;
use App\Entity\MercadoPagoSubscriptionLink;
use App\Entity\Subscription;
use App\Repository\MercadoPagoSubscriptionLinkRepository;
use App\Service\MercadoPagoClient;
use App\Service\MPSubscriptionManager;
use App\Service\PlatformNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class MPSubscriptionManagerTest extends TestCase
{
    public function testEnsureSingleActiveKeepsPreferredAndCancelsOthers(): void
    {
        $subscription = $this->createSubscriptionWithBusiness(10, 'keep');
        $business = $subscription->getBusiness();
        $links = [
            'keep' => (new MercadoPagoSubscriptionLink('keep', 'ACTIVE'))->setBusiness($business),
            'cancel' => (new MercadoPagoSubscriptionLink('cancel', 'ACTIVE'))->setBusiness($business),
        ];

        $mercadoPagoClient = $this->createMock(MercadoPagoClient::class);
        $mercadoPagoClient
            ->expects(self::once())
            ->method('searchPreapprovalsByExternalReference')
            ->willReturn([
                [
                    'id' => 'keep',
                    'status' => 'active',
                    'date_created' => '2024-01-01T00:00:00Z',
                    'last_modified' => null,
                    'reason' => null,
                    'payer_email' => null,
                ],
                [
                    'id' => 'cancel',
                    'status' => 'authorized',
                    'date_created' => '2024-01-02T00:00:00Z',
                    'last_modified' => null,
                    'reason' => null,
                    'payer_email' => null,
                ],
                [
                    'id' => 'pending',
                    'status' => 'pending',
                    'date_created' => '2024-01-03T00:00:00Z',
                    'last_modified' => null,
                    'reason' => null,
                    'payer_email' => null,
                ],
            ]);
        $mercadoPagoClient
            ->expects(self::once())
            ->method('cancelPreapproval')
            ->with('cancel');

        $subscriptionLinkRepository = $this->createMock(MercadoPagoSubscriptionLinkRepository::class);
        $subscriptionLinkRepository
            ->method('findOneBy')
            ->willReturnCallback(function (array $criteria) use ($business, $links) {
                if (($criteria['business'] ?? null) === $business && ($criteria['isPrimary'] ?? null) === true) {
                    return null;
                }
                $id = $criteria['mpPreapprovalId'] ?? null;
                if (is_string($id) && isset($links[$id])) {
                    return $links[$id];
                }

                return null;
            });
        $subscriptionLinkRepository
            ->expects(self::once())
            ->method('clearPrimaryForBusiness')
            ->with($business)
            ->willReturnCallback(function () use ($links) {
                foreach ($links as $link) {
                    $link->setIsPrimary(false);
                }
            });

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $platformNotificationService = $this->createMock(PlatformNotificationService::class);
        $platformNotificationService
            ->expects(self::once())
            ->method('notifyMpInconsistencyIfRepeated')
            ->with($business, 2, 1);

        $manager = new MPSubscriptionManager(
            $mercadoPagoClient,
            $entityManager,
            $subscriptionLinkRepository,
            $this->createMock(UrlGeneratorInterface::class),
            $platformNotificationService,
            $this->createMock(LoggerInterface::class),
            $this->createLockFactoryMock(),
        );

        $result = $manager->ensureSingleActivePreapproval($business, 'keep');

        self::assertTrue($links['keep']->isPrimary());
        self::assertFalse($links['cancel']->isPrimary());
        self::assertSame('CANCELED', $links['cancel']->getStatus());
        self::assertSame('keep', $subscription->getMpPreapprovalId());
        self::assertSame(2, $result->getActiveBefore());
        self::assertSame(1, $result->getActiveAfter());
    }

    public function testEnsureSingleActiveKeepsLocalSubscriptionWhenPreferredMissing(): void
    {
        $subscription = $this->createSubscriptionWithBusiness(11, 'local');
        $business = $subscription->getBusiness();
        $link = (new MercadoPagoSubscriptionLink('local', 'ACTIVE'))->setBusiness($business);

        $mercadoPagoClient = $this->createMock(MercadoPagoClient::class);
        $mercadoPagoClient
            ->expects(self::once())
            ->method('searchPreapprovalsByExternalReference')
            ->willReturn([
                [
                    'id' => 'local',
                    'status' => 'authorized',
                    'date_created' => '2024-01-01T00:00:00Z',
                    'last_modified' => null,
                    'reason' => null,
                    'payer_email' => null,
                ],
                [
                    'id' => 'other',
                    'status' => 'active',
                    'date_created' => '2024-01-02T00:00:00Z',
                    'last_modified' => null,
                    'reason' => null,
                    'payer_email' => null,
                ],
            ]);
        $mercadoPagoClient
            ->expects(self::once())
            ->method('cancelPreapproval')
            ->with('other');

        $subscriptionLinkRepository = $this->createMock(MercadoPagoSubscriptionLinkRepository::class);
        $subscriptionLinkRepository
            ->method('findOneBy')
            ->willReturnCallback(function (array $criteria) use ($business, $link) {
                if (($criteria['business'] ?? null) === $business && ($criteria['isPrimary'] ?? null) === true) {
                    return null;
                }
                if (($criteria['mpPreapprovalId'] ?? null) === 'local') {
                    return $link;
                }

                return null;
            });
        $subscriptionLinkRepository
            ->expects(self::once())
            ->method('clearPrimaryForBusiness')
            ->with($business);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $manager = new MPSubscriptionManager(
            $mercadoPagoClient,
            $entityManager,
            $subscriptionLinkRepository,
            $this->createMock(UrlGeneratorInterface::class),
            $this->createMock(PlatformNotificationService::class),
            $this->createMock(LoggerInterface::class),
            $this->createLockFactoryMock(),
        );

        $result = $manager->ensureSingleActivePreapproval($business, null);

        self::assertTrue($link->isPrimary());
        self::assertSame('local', $result->getKeptPreapprovalId());
    }

    public function testEnsureSingleActiveIsIdempotent(): void
    {
        $subscription = $this->createSubscriptionWithBusiness(12, 'only');
        $business = $subscription->getBusiness();
        $link = (new MercadoPagoSubscriptionLink('only', 'ACTIVE'))->setBusiness($business);

        $mercadoPagoClient = $this->createMock(MercadoPagoClient::class);
        $mercadoPagoClient
            ->expects(self::exactly(2))
            ->method('searchPreapprovalsByExternalReference')
            ->willReturn([
                [
                    'id' => 'only',
                    'status' => 'active',
                    'date_created' => '2024-01-01T00:00:00Z',
                    'last_modified' => null,
                    'reason' => null,
                    'payer_email' => null,
                ],
            ]);
        $mercadoPagoClient
            ->expects(self::never())
            ->method('cancelPreapproval');

        $subscriptionLinkRepository = $this->createMock(MercadoPagoSubscriptionLinkRepository::class);
        $subscriptionLinkRepository
            ->method('findOneBy')
            ->willReturnCallback(function (array $criteria) use ($business, $link) {
                if (($criteria['business'] ?? null) === $business && ($criteria['isPrimary'] ?? null) === true) {
                    return null;
                }
                if (($criteria['mpPreapprovalId'] ?? null) === 'only') {
                    return $link;
                }

                return null;
            });
        $subscriptionLinkRepository
            ->expects(self::exactly(2))
            ->method('clearPrimaryForBusiness')
            ->with($business);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::exactly(2))->method('flush');

        $manager = new MPSubscriptionManager(
            $mercadoPagoClient,
            $entityManager,
            $subscriptionLinkRepository,
            $this->createMock(UrlGeneratorInterface::class),
            $this->createMock(PlatformNotificationService::class),
            $this->createMock(LoggerInterface::class),
            $this->createLockFactoryMock(),
        );

        $first = $manager->ensureSingleActivePreapproval($business, null);
        $second = $manager->ensureSingleActivePreapproval($business, null);

        self::assertSame($first->getKeptPreapprovalId(), $second->getKeptPreapprovalId());
        self::assertSame(1, $second->getActiveAfter());
    }

    public function testEnsureSingleActiveMarksPartialOnCancelFailure(): void
    {
        $subscription = $this->createSubscriptionWithBusiness(13, 'keep');
        $business = $subscription->getBusiness();
        $link = (new MercadoPagoSubscriptionLink('keep', 'ACTIVE'))->setBusiness($business);

        $mercadoPagoClient = $this->createMock(MercadoPagoClient::class);
        $mercadoPagoClient
            ->expects(self::once())
            ->method('searchPreapprovalsByExternalReference')
            ->willReturn([
                [
                    'id' => 'keep',
                    'status' => 'active',
                    'date_created' => '2024-01-01T00:00:00Z',
                    'last_modified' => null,
                    'reason' => null,
                    'payer_email' => null,
                ],
                [
                    'id' => 'fail',
                    'status' => 'authorized',
                    'date_created' => '2024-01-02T00:00:00Z',
                    'last_modified' => null,
                    'reason' => null,
                    'payer_email' => null,
                ],
            ]);
        $mercadoPagoClient
            ->expects(self::once())
            ->method('cancelPreapproval')
            ->with('fail')
            ->willThrowException(new \App\Exception\MercadoPagoApiException(500, 'boom', 'corr-1'));

        $subscriptionLinkRepository = $this->createMock(MercadoPagoSubscriptionLinkRepository::class);
        $subscriptionLinkRepository
            ->method('findOneBy')
            ->willReturnCallback(function (array $criteria) use ($business, $link) {
                if (($criteria['business'] ?? null) === $business && ($criteria['isPrimary'] ?? null) === true) {
                    return null;
                }
                if (($criteria['mpPreapprovalId'] ?? null) === 'keep') {
                    return $link;
                }

                return null;
            });
        $subscriptionLinkRepository
            ->expects(self::once())
            ->method('clearPrimaryForBusiness')
            ->with($business);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects(self::exactly(2))
            ->method('warning')
            ->withConsecutive(
                ['Failed to cancel duplicate MP preapproval.', self::arrayHasKey('correlation_id')],
                ['MP duplicate active preapprovals detected.', self::arrayHasKey('active_count')],
            );

        $manager = new MPSubscriptionManager(
            $mercadoPagoClient,
            $entityManager,
            $subscriptionLinkRepository,
            $this->createMock(UrlGeneratorInterface::class),
            $this->createMock(PlatformNotificationService::class),
            $logger,
            $this->createLockFactoryMock(),
        );

        $result = $manager->ensureSingleActivePreapproval($business, 'keep');

        self::assertTrue($result->isPartial());
        self::assertSame('keep', $result->getKeptPreapprovalId());
    }

    public function testEnsureSingleActiveMarksCancelPendingOnRateLimit(): void
    {
        $subscription = $this->createSubscriptionWithBusiness(14, 'keep');
        $business = $subscription->getBusiness();
        $link = (new MercadoPagoSubscriptionLink('fail', 'ACTIVE'))->setBusiness($business);

        $mercadoPagoClient = $this->createMock(MercadoPagoClient::class);
        $mercadoPagoClient
            ->expects(self::once())
            ->method('searchPreapprovalsByExternalReference')
            ->willReturn([
                [
                    'id' => 'keep',
                    'status' => 'active',
                    'date_created' => '2024-01-01T00:00:00Z',
                    'last_modified' => null,
                    'reason' => null,
                    'payer_email' => null,
                ],
                [
                    'id' => 'fail',
                    'status' => 'authorized',
                    'date_created' => '2024-01-02T00:00:00Z',
                    'last_modified' => null,
                    'reason' => null,
                    'payer_email' => null,
                ],
            ]);
        $mercadoPagoClient
            ->expects(self::once())
            ->method('cancelPreapproval')
            ->with('fail')
            ->willThrowException(new \App\Exception\MercadoPagoApiException(429, 'local_rate_limited', 'corr-2'));

        $subscriptionLinkRepository = $this->createMock(MercadoPagoSubscriptionLinkRepository::class);
        $subscriptionLinkRepository
            ->method('findOneBy')
            ->willReturnCallback(function (array $criteria) use ($business, $link) {
                if (($criteria['business'] ?? null) === $business && ($criteria['isPrimary'] ?? null) === true) {
                    return null;
                }
                if (($criteria['mpPreapprovalId'] ?? null) === 'fail') {
                    return $link;
                }

                return null;
            });
        $subscriptionLinkRepository
            ->expects(self::once())
            ->method('clearPrimaryForBusiness')
            ->with($business);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $manager = new MPSubscriptionManager(
            $mercadoPagoClient,
            $entityManager,
            $subscriptionLinkRepository,
            $this->createMock(UrlGeneratorInterface::class),
            $this->createMock(PlatformNotificationService::class),
            $this->createMock(LoggerInterface::class),
            $this->createLockFactoryMock(),
        );

        $manager->ensureSingleActivePreapproval($business, 'keep');

        self::assertSame('CANCEL_PENDING', $link->getStatus());
        self::assertNotNull($link->getLastAttemptAt());
    }

    private function createSubscriptionWithBusiness(int $businessId, string $mpPreapprovalId): Subscription
    {
        $business = new Business();
        $reflection = new \ReflectionProperty(Business::class, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($business, $businessId);

        $subscription = new Subscription();
        $subscription->setMpPreapprovalId($mpPreapprovalId);
        $business->setSubscription($subscription);

        return $subscription;
    }

    private function createLockFactoryMock(): LockFactory
    {
        $lock = $this->createMock(LockInterface::class);
        $lock->method('acquire')->willReturn(true);
        $lock->method('release')->willReturn(true);

        $factory = $this->createMock(LockFactory::class);
        $factory->method('createLock')->willReturn($lock);

        return $factory;
    }
}
