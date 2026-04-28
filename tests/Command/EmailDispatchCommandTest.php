<?php

namespace App\Tests\Command;

use App\Command\EmailDispatchCommand;
use App\Repository\BusinessRepository;
use App\Repository\EmailPreferenceRepository;
use App\Service\EmailSender;
use App\Service\PlatformNotificationService;
use App\Service\ReportDigestBuilder;
use App\Service\ReportNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class EmailDispatchCommandTest extends TestCase
{
    public function testDoesNotExecuteDispatchWhenLockIsNotAcquired(): void
    {
        $businessRepository = $this->createMock(BusinessRepository::class);
        $businessRepository->expects(self::never())->method('findAll');

        $command = new class(
            $businessRepository,
            $this->createMock(EmailPreferenceRepository::class),
            $this->createMock(ReportDigestBuilder::class),
            $this->createMock(ReportNotificationService::class),
            $this->createMock(PlatformNotificationService::class),
            $this->createMock(EmailSender::class),
            $this->createMock(EntityManagerInterface::class),
            true
        ) extends EmailDispatchCommand {
            protected function acquireExecutionLock(): bool
            {
                return false;
            }

            protected function releaseExecutionLock(): void
            {
            }
        };

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Ya existe una ejecución activa de app:emails:dispatch', $tester->getDisplay());
    }
}
