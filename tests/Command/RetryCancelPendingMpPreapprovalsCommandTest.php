<?php

namespace App\Tests\Command;

use App\Command\RetryCancelPendingMpPreapprovalsCommand;
use App\Entity\Business;
use App\Entity\MercadoPagoSubscriptionLink;
use App\Repository\MercadoPagoSubscriptionLinkRepository;
use App\Service\MercadoPagoClient;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;

class RetryCancelPendingMpPreapprovalsCommandTest extends TestCase
{
    public function testRetriesPendingCancellations(): void
    {
        $business = $this->createBusinessWithId(42);
        $link = new MercadoPagoSubscriptionLink('mp-1', 'CANCEL_PENDING');
        $link->setBusiness($business);
        $link->setIsPrimary(true);

        $repository = $this->createMock(MercadoPagoSubscriptionLinkRepository::class);
        $repository
            ->expects(self::once())
            ->method('findCancelPending')
            ->with(50)
            ->willReturn([$link]);

        $client = $this->createMock(MercadoPagoClient::class);
        $client
            ->expects(self::once())
            ->method('cancelPreapproval')
            ->with('mp-1');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::once())
            ->method('flush');

        $command = new RetryCancelPendingMpPreapprovalsCommand(
            $repository,
            $client,
            $entityManager,
            $this->createMock(LoggerInterface::class),
        );

        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertSame('CANCELED', $link->getStatus());
        self::assertFalse($link->isPrimary());
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
