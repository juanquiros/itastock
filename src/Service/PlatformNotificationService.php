<?php

namespace App\Service;

use App\Entity\Business;
use App\Entity\BusinessUser;
use App\Entity\EmailNotificationLog;
use App\Entity\Lead;
use App\Entity\Subscription;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

class PlatformNotificationService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EmailSender $emailSender,
        private readonly string $platformNotifyEmails,
    ) {
    }

    public function notifyNewSubscription(Business $business, Subscription $subscription): array
    {
        $context = [
            'businessName' => $business->getName(),
            'adminEmail' => $this->findBusinessAdminEmail($business),
            'planName' => $subscription->getPlan()?->getName(),
        ];

        return $this->notifyPlatformAdmins(
            'PLATFORM_NEW_SUBSCRIPTION',
            'Nueva suscripción',
            'emails/platform/platform_new_subscription.html.twig',
            $context,
            $subscription,
            null,
            null,
        );
    }

    public function notifyCancellation(Business $business, Subscription $subscription): array
    {
        $context = [
            'businessName' => $business->getName(),
            'adminEmail' => $this->findBusinessAdminEmail($business),
            'endsAt' => $subscription->getEndAt(),
        ];

        return $this->notifyPlatformAdmins(
            'PLATFORM_CANCELLATION',
            'Suscripción cancelada',
            'emails/platform/platform_cancellation.html.twig',
            $context,
            $subscription,
            null,
            null,
        );
    }

    public function notifyDemoRequest(Lead $lead): array
    {
        $context = [
            'businessName' => $lead->getBusinessName() ?? $lead->getName(),
            'contactEmail' => $lead->getEmail(),
        ];

        return $this->notifyPlatformAdmins(
            'PLATFORM_DEMO_REQUEST',
            'Nueva solicitud de demo',
            'emails/platform/platform_demo_request.html.twig',
            $context,
            null,
            null,
            null,
        );
    }

    public function sendWeeklyPlatformDigest(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $digest = $this->buildDigestContext($start, $end);

        return $this->notifyPlatformAdmins(
            'PLATFORM_DIGEST_WEEKLY',
            'Digest semanal plataforma',
            'emails/platform/platform_digest_weekly.html.twig',
            $digest,
            null,
            $start,
            $end,
        );
    }

    public function sendMonthlyPlatformDigest(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $digest = $this->buildDigestContext($start, $end);

        return $this->notifyPlatformAdmins(
            'PLATFORM_DIGEST_MONTHLY',
            'Digest mensual plataforma',
            'emails/platform/platform_digest_monthly.html.twig',
            $digest,
            null,
            $start,
            $end,
        );
    }

    public function notifySubscriptionChangeScheduled(Business $business, Subscription $subscription, ?string $planName, ?\DateTimeImmutable $effectiveAt, ?\DateTimeImmutable $currentEndsAt): array
    {
        $context = [
            'businessName' => $business->getName(),
            'adminEmail' => $this->findBusinessAdminEmail($business),
            'planName' => $planName,
            'effectiveAt' => $effectiveAt,
            'currentEndsAt' => $currentEndsAt,
        ];

        return $this->notifyPlatformAdmins(
            'PLATFORM_SUBSCRIPTION_CHANGE_SCHEDULED',
            'Cambio de plan programado',
            'emails/platform/platform_subscription_change_scheduled.html.twig',
            $context,
            $subscription,
            null,
            null,
        );
    }

    public function notifySubscriptionChangePaid(Business $business, Subscription $subscription, ?string $planName, ?\DateTimeImmutable $effectiveAt, ?\DateTimeImmutable $paidAt): array
    {
        $context = [
            'businessName' => $business->getName(),
            'adminEmail' => $this->findBusinessAdminEmail($business),
            'planName' => $planName,
            'effectiveAt' => $effectiveAt,
            'paidAt' => $paidAt,
        ];

        return $this->notifyPlatformAdmins(
            'PLATFORM_SUBSCRIPTION_CHANGE_PAID',
            'Pago recibido (cambio de plan)',
            'emails/platform/platform_subscription_change_paid.html.twig',
            $context,
            $subscription,
            null,
            null,
        );
    }

    public function notifySubscriptionChangeApplied(Business $business, Subscription $subscription, ?string $planName, ?\DateTimeImmutable $appliedAt): array
    {
        $context = [
            'businessName' => $business->getName(),
            'adminEmail' => $this->findBusinessAdminEmail($business),
            'planName' => $planName,
            'appliedAt' => $appliedAt,
        ];

        return $this->notifyPlatformAdmins(
            'PLATFORM_SUBSCRIPTION_CHANGE_APPLIED',
            'Cambio aplicado',
            'emails/platform/platform_subscription_change_applied.html.twig',
            $context,
            $subscription,
            null,
            null,
        );
    }

    public function notifySubscriptionChangeExpired(Business $business, Subscription $subscription, ?string $planName, ?\DateTimeImmutable $expiredAt): array
    {
        $context = [
            'businessName' => $business->getName(),
            'adminEmail' => $this->findBusinessAdminEmail($business),
            'planName' => $planName,
            'expiredAt' => $expiredAt,
        ];

        return $this->notifyPlatformAdmins(
            'PLATFORM_SUBSCRIPTION_CHANGE_EXPIRED',
            'Cambio de plan expirado',
            'emails/platform/platform_subscription_change_expired.html.twig',
            $context,
            $subscription,
            null,
            null,
        );
    }

    public function notifyMpInconsistencyIfRepeated(Business $business, int $activeCount, int $canceledCount, int $threshold = 3): void
    {
        $this->recordMpInconsistencyOccurrence($business, $activeCount, $canceledCount);

        $now = new \DateTimeImmutable();
        $windowStart = $now->modify('-24 hours');

        $occurrences = $this->countMpInconsistencyOccurrences($business, $windowStart);
        if ($occurrences < $threshold) {
            return;
        }

        if ($this->hasRecentMpInconsistencyAlert($business, $windowStart)) {
            return;
        }

        $context = [
            'businessName' => $business->getName(),
            'businessId' => $business->getId(),
            'activeCount' => $activeCount,
            'canceledCount' => $canceledCount,
        ];

        $this->notifyPlatformAdmins(
            'PLATFORM_MP_INCONSISTENCY',
            'Inconsistencia de suscripciones',
            'emails/platform/platform_subscription_inconsistency.html.twig',
            $context,
            null,
            $windowStart,
            $now,
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    private function notifyPlatformAdmins(
        string $type,
        string $subject,
        string $template,
        array $context,
        ?Subscription $subscription,
        ?\DateTimeImmutable $periodStart,
        ?\DateTimeImmutable $periodEnd,
    ): array {
        $counts = [
            EmailNotificationLog::STATUS_SENT => 0,
            EmailNotificationLog::STATUS_SKIPPED => 0,
            EmailNotificationLog::STATUS_FAILED => 0,
        ];

        foreach ($this->getPlatformRecipients() as $recipientEmail) {
            $status = $this->emailSender->sendTemplatedEmail(
                $type,
                $recipientEmail,
                'PLATFORM',
                $subject,
                $template,
                $context,
                null,
                $subscription,
                $periodStart,
                $periodEnd,
            );

            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @return string[]
     */
    private function getPlatformRecipients(): array
    {
        $recipients = [];

        $qb = $this->entityManager->createQueryBuilder();
        $users = $qb
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.roles LIKE :role')
            ->setParameter('role', '%ROLE_PLATFORM_ADMIN%')
            ->getQuery()
            ->getResult();

        foreach ($users as $user) {
            if (!$user instanceof User) {
                continue;
            }

            $recipients[] = $user->getEmail() ?? $user->getUserIdentifier();
        }

        if ($this->platformNotifyEmails !== '') {
            $fallbacks = array_filter(array_map('trim', explode(',', $this->platformNotifyEmails)));
            $recipients = array_merge($recipients, $fallbacks);
        }

        $recipients = array_values(array_unique(array_filter($recipients)));

        return $recipients;
    }

    private function findBusinessAdminEmail(Business $business): ?string
    {
        foreach ($business->getBusinessUsers() as $membership) {
            $user = $membership->getUser();
            if (
                !$user instanceof User
                || !$membership->isActive()
                || !in_array($membership->getRole(), [BusinessUser::ROLE_OWNER, BusinessUser::ROLE_ADMIN], true)
            ) {
                continue;
            }

            return $user->getEmail() ?? $user->getUserIdentifier();
        }

        return null;
    }

    private function recordMpInconsistencyOccurrence(Business $business, int $activeCount, int $canceledCount): void
    {
        $log = (new EmailNotificationLog())
            ->setType('PLATFORM_MP_INCONSISTENCY')
            ->setRecipientEmail('platform')
            ->setRecipientRole(EmailNotificationLog::ROLE_PLATFORM)
            ->setBusiness($business)
            ->setStatus(EmailNotificationLog::STATUS_SKIPPED)
            ->setErrorMessage(sprintf(
                'Occurrence recorded. Active=%d, Canceled=%d',
                $activeCount,
                $canceledCount
            ));

        $this->entityManager->persist($log);
        $this->entityManager->flush();
    }

    private function countMpInconsistencyOccurrences(Business $business, \DateTimeImmutable $since): int
    {
        $qb = $this->entityManager->createQueryBuilder();

        return (int) $qb
            ->select('COUNT(e.id)')
            ->from(EmailNotificationLog::class, 'e')
            ->where('e.type = :type')
            ->andWhere('e.business = :business')
            ->andWhere('e.createdAt >= :since')
            ->setParameter('type', 'PLATFORM_MP_INCONSISTENCY')
            ->setParameter('business', $business)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function hasRecentMpInconsistencyAlert(Business $business, \DateTimeImmutable $since): bool
    {
        $qb = $this->entityManager->createQueryBuilder();

        $count = (int) $qb
            ->select('COUNT(e.id)')
            ->from(EmailNotificationLog::class, 'e')
            ->where('e.type = :type')
            ->andWhere('e.status = :status')
            ->andWhere('e.business = :business')
            ->andWhere('e.createdAt >= :since')
            ->setParameter('type', 'PLATFORM_MP_INCONSISTENCY')
            ->setParameter('status', EmailNotificationLog::STATUS_SENT)
            ->setParameter('business', $business)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildDigestContext(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $now = $end;
        $notes = [];

        $newSubscriptions = $this->countSubscriptionsByPeriod($start, $end);
        $cancellations = $this->countSubscriptionsByStatusInPeriod(Subscription::STATUS_CANCELED, $start, $end);
        $demoRequests = $this->countLeadsByPeriod($start, $end);
        $activeTrials = $this->countTrialsActiveAt($now);
        $trialsExpiring = $this->countTrialsExpiringSoon($now);
        $expiredTrials = $this->countTrialsExpiredBefore($now);
        $pastDueSuspended = $this->countSubscriptionsByStatuses([
            Subscription::STATUS_PAST_DUE,
            Subscription::STATUS_SUSPENDED,
        ]);
        $newBusinesses = $this->countBusinessesByPeriod($start, $end);

        $mrr = $this->calculateEstimatedMrr();
        if ($mrr === null) {
            $notes[] = 'N/D: MRR estimado';
        }

        return [
            'periodStart' => $start,
            'periodEnd' => $end,
            'newSubscriptions' => $newSubscriptions,
            'canceledSubscriptions' => $cancellations,
            'newBusinesses' => $newBusinesses,
            'activeSubscriptions' => $this->countSubscriptionsByStatus(Subscription::STATUS_ACTIVE),
            'demoRequests' => $demoRequests,
            'activeTrials' => $activeTrials,
            'trialsExpiring' => $trialsExpiring,
            'expiredTrials' => $expiredTrials,
            'pastDueSuspended' => $pastDueSuspended,
            'estimatedMrr' => $mrr,
            'notes' => $notes,
        ];
    }

    protected function countSubscriptionsByPeriod(\DateTimeImmutable $start, \DateTimeImmutable $end): int
    {
        $qb = $this->entityManager->createQueryBuilder();

        return (int) $qb
            ->select('COUNT(s.id)')
            ->from(Subscription::class, 's')
            ->where('s.createdAt BETWEEN :start AND :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getSingleScalarResult();
    }

    protected function countSubscriptionsByStatusInPeriod(string $status, \DateTimeImmutable $start, \DateTimeImmutable $end): int
    {
        $qb = $this->entityManager->createQueryBuilder();

        return (int) $qb
            ->select('COUNT(s.id)')
            ->from(Subscription::class, 's')
            ->where('s.status = :status')
            ->andWhere('s.updatedAt BETWEEN :start AND :end')
            ->setParameter('status', $status)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getSingleScalarResult();
    }

    protected function countSubscriptionsByStatuses(array $statuses): int
    {
        $qb = $this->entityManager->createQueryBuilder();

        return (int) $qb
            ->select('COUNT(s.id)')
            ->from(Subscription::class, 's')
            ->where('s.status IN (:statuses)')
            ->setParameter('statuses', $statuses)
            ->getQuery()
            ->getSingleScalarResult();
    }

    protected function countSubscriptionsByStatus(string $status): int
    {
        return $this->countSubscriptionsByStatuses([$status]);
    }

    protected function countLeadsByPeriod(\DateTimeImmutable $start, \DateTimeImmutable $end): int
    {
        $qb = $this->entityManager->createQueryBuilder();

        return (int) $qb
            ->select('COUNT(l.id)')
            ->from(Lead::class, 'l')
            ->where('l.createdAt BETWEEN :start AND :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getSingleScalarResult();
    }

    protected function countBusinessesByPeriod(\DateTimeImmutable $start, \DateTimeImmutable $end): int
    {
        $qb = $this->entityManager->createQueryBuilder();

        return (int) $qb
            ->select('COUNT(b.id)')
            ->from(Business::class, 'b')
            ->where('b.createdAt BETWEEN :start AND :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getSingleScalarResult();
    }

    protected function countTrialsActiveAt(\DateTimeImmutable $now): int
    {
        $qb = $this->entityManager->createQueryBuilder();

        return (int) $qb
            ->select('COUNT(s.id)')
            ->from(Subscription::class, 's')
            ->where('s.status = :status')
            ->andWhere('s.trialEndsAt >= :now')
            ->setParameter('status', Subscription::STATUS_TRIAL)
            ->setParameter('now', $now)
            ->getQuery()
            ->getSingleScalarResult();
    }

    protected function countTrialsExpiringSoon(\DateTimeImmutable $now): int
    {
        $windowEnd = $now->modify('+3 days');

        $qb = $this->entityManager->createQueryBuilder();

        return (int) $qb
            ->select('COUNT(s.id)')
            ->from(Subscription::class, 's')
            ->where('s.status = :status')
            ->andWhere('s.trialEndsAt BETWEEN :start AND :end')
            ->setParameter('status', Subscription::STATUS_TRIAL)
            ->setParameter('start', $now)
            ->setParameter('end', $windowEnd)
            ->getQuery()
            ->getSingleScalarResult();
    }

    protected function countTrialsExpiredBefore(\DateTimeImmutable $now): int
    {
        $qb = $this->entityManager->createQueryBuilder();

        return (int) $qb
            ->select('COUNT(s.id)')
            ->from(Subscription::class, 's')
            ->where('s.status = :status')
            ->andWhere('s.trialEndsAt < :now')
            ->setParameter('status', Subscription::STATUS_TRIAL)
            ->setParameter('now', $now)
            ->getQuery()
            ->getSingleScalarResult();
    }

    protected function calculateEstimatedMrr(): ?float
    {
        $qb = $this->entityManager->createQueryBuilder();
        $result = $qb
            ->select('SUM(p.priceMonthly) as total')
            ->from(Subscription::class, 's')
            ->join('s.plan', 'p')
            ->where('s.status = :status')
            ->setParameter('status', Subscription::STATUS_ACTIVE)
            ->getQuery()
            ->getSingleScalarResult();

        if ($result === null) {
            return null;
        }

        return (float) $result;
    }
}
