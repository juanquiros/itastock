<?php

namespace App\Tests\Service;

use App\Entity\Business;
use App\Entity\MercadoPagoSubscriptionLink;
use App\Repository\MercadoPagoSubscriptionLinkRepository;
use App\Service\MercadoPagoClient;
use App\Service\MPSubscriptionManager;
use App\Service\PlatformNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class MPSubscriptionManagerTest extends TestCase
{
    public function testEnsureSingleActiveKeepsPreferredAndCancelsOthers(): void
    {
        $business = $this->createBusinessWithId(10);
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
        );

        $manager->ensureSingleActiveAfterMutation($business, 'keep');

        self::assertTrue($links['keep']->isPrimary());
        self::assertFalse($links['cancel']->isPrimary());
        self::assertSame('CANCELED', $links['cancel']->getStatus());
    }

    public function testEnsureSingleActiveSetsPrimaryWhenSingleActive(): void
    {
        $business = $this->createBusinessWithId(11);
        $link = (new MercadoPagoSubscriptionLink('only', 'ACTIVE'))->setBusiness($business);

        $mercadoPagoClient = $this->createMock(MercadoPagoClient::class);
        $mercadoPagoClient
            ->expects(self::once())
            ->method('searchPreapprovalsByExternalReference')
            ->willReturn([
                [
                    'id' => 'only',
                    'status' => 'authorized',
                    'date_created' => '2024-01-01T00:00:00Z',
                    'last_modified' => null,
                    'reason' => null,
                    'payer_email' => null,
                ],
            ]);

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
        );

        $manager->ensureSingleActiveAfterMutation($business, null);

        self::assertTrue($link->isPrimary());
    }

    private function createBusinessWithId(int $id): Business
    {
        $business = new Business();
        $reflection = new \ReflectionProperty(Business::class, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($business, $id);

        return $business;
    }
}
