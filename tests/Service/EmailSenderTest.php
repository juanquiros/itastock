<?php

namespace App\Tests\Service;

use App\Entity\EmailNotificationLog;
use App\Entity\Subscription;
use App\Security\EmailContentPolicy;
use App\Service\EmailSender;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;

class EmailSenderTest extends TestCase
{
    public function testSkipsDuplicateByPeriod(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $repository = $this->createMock(ObjectRepository::class);
        $repository->expects(self::once())
            ->method('findOneBy')
            ->with(self::arrayHasKey('periodStart'))
            ->willReturn(new EmailNotificationLog());

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);
        $entityManager->expects(self::once())->method('persist');
        $entityManager->expects(self::once())->method('flush');

        $sender = new EmailSender($mailer, $entityManager, new EmailContentPolicy(), 'no-reply@test', 'ItaStock');

        $status = $sender->sendTemplatedEmail(
            'REPORT_WEEKLY',
            'admin@example.com',
            'ADMIN',
            'Reporte semanal',
            'emails/reports/report_weekly.html.twig',
            [],
            null,
            null,
            new \DateTimeImmutable('2024-01-01'),
            new \DateTimeImmutable('2024-01-07'),
        );

        self::assertSame(EmailNotificationLog::STATUS_SKIPPED, $status);
    }

    public function testSkipsDuplicateBySubscription(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $repository = $this->createMock(ObjectRepository::class);
        $repository->expects(self::once())
            ->method('findOneBy')
            ->with(self::arrayHasKey('subscription'))
            ->willReturn(new EmailNotificationLog());

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);
        $entityManager->expects(self::once())->method('persist');
        $entityManager->expects(self::once())->method('flush');

        $subscription = new Subscription();

        $sender = new EmailSender($mailer, $entityManager, new EmailContentPolicy(), 'no-reply@test', 'ItaStock');

        $status = $sender->sendTemplatedEmail(
            'SUBSCRIPTION_ACTIVATED',
            'admin@example.com',
            'ADMIN',
            'Suscripción activada',
            'emails/subscription/subscription_activated.html.twig',
            [],
            null,
            $subscription,
            null,
            null,
        );

        self::assertSame(EmailNotificationLog::STATUS_SKIPPED, $status);
    }
}
