<?php

namespace App\Tests\Service;

use App\Entity\Business;
use App\Entity\EmailPreference;
use App\Entity\User;
use App\Repository\EmailPreferenceRepository;
use App\Service\EmailSender;
use App\Service\ReportDigestBuilder;
use App\Service\ReportNotificationService;
use PHPUnit\Framework\TestCase;

class ReportNotificationServiceTest extends TestCase
{
    public function testReportRespectsDisabledPreferences(): void
    {
        $business = new Business();
        $business->setName('Demo');

        $user = new User();
        $user->setEmail('admin@example.com');
        $user->setFullName('Admin');
        $user->setRoles(['ROLE_ADMIN']);
        $user->setBusiness($business);
        $business->addUser($user);

        $preference = new EmailPreference();
        $preference->setBusiness($business);
        $preference->setUser($user);
        $preference->setEnabled(false);

        $preferenceRepository = $this->createMock(EmailPreferenceRepository::class);
        $preferenceRepository->expects(self::once())
            ->method('getEffectivePreference')
            ->with($business, $user)
            ->willReturn($preference);

        $digestBuilder = $this->createMock(ReportDigestBuilder::class);
        $digestBuilder->expects(self::once())
            ->method('buildDaily')
            ->with($business, self::isInstanceOf(\DateTimeImmutable::class));

        $emailSender = $this->createMock(EmailSender::class);
        $emailSender->expects(self::never())->method('sendTemplatedEmail');

        $service = new ReportNotificationService($preferenceRepository, $digestBuilder, $emailSender);
        $service->sendDailyIfEnabled($business, new \DateTimeImmutable('2024-01-01'));
    }
}
