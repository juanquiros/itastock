<?php

namespace App\Tests\Service;

use App\Entity\EmailNotificationLog;
use App\Entity\Subscription;
use App\Security\EmailContentPolicy;
use App\Service\EmailSender;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;

class EmailSenderTest extends TestCase
{
    public function testSkipsDuplicateByPeriodWhenReservationConflicts(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('isOpen')->willReturn(true);
        $entityManager->expects(self::once())->method('persist');
        $entityManager->expects(self::once())
            ->method('flush')
            ->willThrowException(new \RuntimeException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry'));

        $sender = $this->buildSender($mailer, $entityManager);

        $status = $sender->sendTemplatedEmail(
            'REPORT_WEEKLY',
            'admin@example.com',
            'ADMIN',
            'Reporte semanal',
            'emails/reports/report_weekly.html.twig',
            ['kpi' => 1],
            null,
            null,
            new \DateTimeImmutable('2024-01-01'),
            new \DateTimeImmutable('2024-01-07'),
        );

        self::assertSame(EmailNotificationLog::STATUS_SKIPPED, $status);
    }

    public function testSkipsDuplicateBySubscriptionContextWhenReservationConflicts(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('isOpen')->willReturn(true);
        $entityManager->expects(self::once())->method('persist');
        $entityManager->expects(self::once())
            ->method('flush')
            ->willThrowException(new \RuntimeException('Duplicate entry for key uniq_email_notification_log_idempotency'));

        $subscription = (new Subscription())->setStatus(Subscription::STATUS_ACTIVE);

        $sender = $this->buildSender($mailer, $entityManager);

        $status = $sender->sendTemplatedEmail(
            'SUBSCRIPTION_ACTIVATED',
            'admin@example.com',
            'ADMIN',
            'Suscripción activada',
            'emails/subscription/subscription_activated.html.twig',
            ['planName' => 'Pro'],
            null,
            $subscription,
            null,
            null,
        );

        self::assertSame(EmailNotificationLog::STATUS_SKIPPED, $status);
    }

    public function testIfReservationFailsWithUniqueConstraintItDoesNotSend(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('isOpen')->willReturn(true);
        $entityManager->expects(self::once())->method('persist');
        $entityManager->expects(self::once())
            ->method('flush')
            ->willThrowException(new \RuntimeException('SQLSTATE[23505]: unique constraint violation'));

        $sender = $this->buildSender($mailer, $entityManager);

        $status = $sender->sendTemplatedEmail(
            'DEMO_EXPIRED',
            'admin@example.com',
            'ADMIN',
            'Tu demo finalizó',
            'emails/demo/demo_expired.html.twig',
            ['trialEndsAt' => new \DateTimeImmutable('2024-01-05')],
            null,
            null,
            new \DateTimeImmutable('2024-01-05 00:00:00'),
            new \DateTimeImmutable('2024-01-05 23:59:59'),
        );

        self::assertSame(EmailNotificationLog::STATUS_SKIPPED, $status);
    }

    public function testMailerFailureMarksLogAsFailed(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())
            ->method('send')
            ->willThrowException(new \RuntimeException('SMTP down'));

        $capturedLog = null;
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('isOpen')->willReturn(true);
        $entityManager->expects(self::exactly(2))
            ->method('persist')
            ->willReturnCallback(static function ($log) use (&$capturedLog): void {
                $capturedLog = $log;
            });
        $entityManager->expects(self::exactly(2))->method('flush');

        $sender = $this->buildSender($mailer, $entityManager);

        $status = $sender->sendTemplatedEmail(
            'DEMO_EXPIRED',
            'admin@example.com',
            'ADMIN',
            'Tu demo finalizó',
            'emails/demo/demo_expired.html.twig',
            ['trialEndsAt' => new \DateTimeImmutable('2024-01-05')],
            null,
            null,
            new \DateTimeImmutable('2024-01-05 00:00:00'),
            new \DateTimeImmutable('2024-01-05 23:59:59'),
        );

        self::assertSame(EmailNotificationLog::STATUS_FAILED, $status);
        self::assertInstanceOf(EmailNotificationLog::class, $capturedLog);
        self::assertSame(EmailNotificationLog::STATUS_FAILED, $capturedLog->getStatus());
        self::assertSame('SMTP down', $capturedLog->getErrorMessage());
    }

    public function testSuccessfulSendMarksLogAsSent(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send');

        $capturedLog = null;
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('isOpen')->willReturn(true);
        $entityManager->expects(self::exactly(2))
            ->method('persist')
            ->willReturnCallback(static function ($log) use (&$capturedLog): void {
                $capturedLog = $log;
            });
        $entityManager->expects(self::exactly(2))->method('flush');

        $sender = $this->buildSender($mailer, $entityManager);

        $status = $sender->sendTemplatedEmail(
            'DEMO_EXPIRED',
            'admin@example.com',
            'ADMIN',
            'Tu demo finalizó',
            'emails/demo/demo_expired.html.twig',
            ['trialEndsAt' => new \DateTimeImmutable('2024-01-05')],
            null,
            null,
            new \DateTimeImmutable('2024-01-05 00:00:00'),
            new \DateTimeImmutable('2024-01-05 23:59:59'),
        );

        self::assertSame(EmailNotificationLog::STATUS_SENT, $status);
        self::assertInstanceOf(EmailNotificationLog::class, $capturedLog);
        self::assertSame(EmailNotificationLog::STATUS_SENT, $capturedLog->getStatus());
        self::assertInstanceOf(\DateTimeImmutable::class, $capturedLog->getSentAt());
    }

    private function buildSender(MailerInterface $mailer, EntityManagerInterface $entityManager): EmailSender
    {
        return new EmailSender(
            $mailer,
            $entityManager,
            new EmailContentPolicy(),
            'no-reply@test',
            'ItaStock',
            dirname(__DIR__, 2)
        );
    }
}
