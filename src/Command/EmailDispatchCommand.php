<?php

namespace App\Command;

use App\DTO\ReportDigest;
use App\Entity\Business;
use App\Entity\EmailNotificationLog;
use App\Entity\EmailPreference;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\BusinessRepository;
use App\Repository\EmailPreferenceRepository;
use App\Service\EmailSender;
use App\Service\PlatformNotificationService;
use App\Service\ReportDigestBuilder;
use App\Service\ReportNotificationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:emails:dispatch',
    description: 'Dispatch scheduled emails for subscriptions, reports, and platform digests.'
)]
class EmailDispatchCommand extends Command
{
    public function __construct(
        private readonly BusinessRepository $businessRepository,
        private readonly EmailPreferenceRepository $emailPreferenceRepository,
        private readonly ReportDigestBuilder $reportDigestBuilder,
        private readonly ReportNotificationService $reportNotificationService,
        private readonly PlatformNotificationService $platformNotificationService,
        private readonly EmailSender $emailSender,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Dispatch type: all|subscriptions|reports|platform', 'all')
            ->addOption('dry-run', null, InputOption::VALUE_OPTIONAL, 'Simulate sends without DB logs', '0')
            ->addOption('date', null, InputOption::VALUE_OPTIONAL, 'Override date (YYYY-MM-DD)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $type = (string) $input->getOption('type');
        $dryRun = (string) $input->getOption('dry-run') === '1';
        $now = $this->resolveNow($input->getOption('date'));

        $counts = [];

        if ($type === 'all' || $type === 'subscriptions') {
            $this->dispatchSubscriptionNotifications($now, $dryRun, $output, $counts);
        }

        if ($type === 'all' || $type === 'reports') {
            $this->dispatchReportNotifications($now, $dryRun, $output, $counts);
        }

        if ($type === 'all' || $type === 'platform') {
            $this->dispatchPlatformDigests($now, $dryRun, $output, $counts);
        }

        $this->renderSummary($output, $counts);

        return Command::SUCCESS;
    }

    private function dispatchSubscriptionNotifications(\DateTimeImmutable $now, bool $dryRun, OutputInterface $output, array &$counts): void
    {
        foreach ($this->businessRepository->findAll() as $business) {
            if (!$business instanceof Business) {
                continue;
            }

            $subscription = $business->getSubscription();
            if (!$subscription instanceof Subscription) {
                continue;
            }

            $this->handleSubscriptionRule(
                $subscription,
                'DEMO_EXPIRING_3_DAYS',
                'Tu demo termina en 3 días',
                'emails/demo/demo_expiring_3_days.html.twig',
                $now,
                $dryRun,
                $counts,
                $output,
                fn (Subscription $subscription, \DateTimeImmutable $now): ?array => $this->trialExpiringWindow($subscription, $now),
                fn (Subscription $subscription): array => ['trialEndsAt' => $subscription->getTrialEndsAt()],
            );

            $this->handleSubscriptionRule(
                $subscription,
                'DEMO_EXPIRED',
                'Tu demo finalizó',
                'emails/demo/demo_expired.html.twig',
                $now,
                $dryRun,
                $counts,
                $output,
                fn (Subscription $subscription, \DateTimeImmutable $now): ?array => $this->trialExpiredWindow($subscription, $now),
                fn (Subscription $subscription): array => ['trialEndsAt' => $subscription->getTrialEndsAt()],
            );

            $this->handleSubscriptionRule(
                $subscription,
                'SUBSCRIPTION_NEXT_CHARGE_7_DAYS',
                'Recordatorio de próximo cobro',
                'emails/subscription/subscription_next_charge_7_days.html.twig',
                $now,
                $dryRun,
                $counts,
                $output,
                fn (Subscription $subscription, \DateTimeImmutable $now): ?array => $this->nextChargeWindow($subscription, $now),
                fn (Subscription $subscription): array => [
                    'nextChargeAt' => $subscription->getNextPaymentAt() ?? $subscription->getEndAt(),
                    'planName' => $subscription->getPlan()?->getName(),
                ],
            );

            $this->handleSubscriptionRule(
                $subscription,
                'SUBSCRIPTION_CANCELED_OR_EXPIRING_7_DAYS',
                'Tu suscripción está por finalizar',
                'emails/subscription/subscription_canceled_or_expiring_7_days.html.twig',
                $now,
                $dryRun,
                $counts,
                $output,
                fn (Subscription $subscription, \DateTimeImmutable $now): ?array => $this->endAtWindow($subscription, $now),
                fn (Subscription $subscription): array => [
                    'planName' => $subscription->getPlan()?->getName(),
                    'endsAt' => $subscription->getEndAt(),
                ],
            );

            $this->handleSubscriptionRule(
                $subscription,
                'SUBSCRIPTION_NO_SUBSCRIPTION',
                'Tu comercio no tiene una suscripción activa',
                'emails/subscription/subscription_no_subscription.html.twig',
                $now,
                $dryRun,
                $counts,
                $output,
                fn (Subscription $subscription, \DateTimeImmutable $now): ?array => $this->noSubscriptionWindow($subscription, $now),
                fn (Subscription $subscription): array => [
                    'businessName' => $subscription->getBusiness()?->getName(),
                ],
            );
        }
    }

    private function dispatchReportNotifications(\DateTimeImmutable $now, bool $dryRun, OutputInterface $output, array &$counts): void
    {
        foreach ($this->businessRepository->findAll() as $business) {
            if (!$business instanceof Business) {
                continue;
            }

            $admins = $this->getAdminUsers($business);
            if ($admins === []) {
                continue;
            }

            $dailyRecipients = $this->filterReportRecipients($admins, $now, 'daily');
            if ($dailyRecipients !== []) {
                $digest = $this->reportDigestBuilder->buildDaily($business, $now);
                $this->sendReportToRecipients('REPORT_DAILY', 'Reporte diario', 'emails/reports/report_daily.html.twig', $digest, $business, $dailyRecipients, $dryRun, $output, $counts);
            }

            $weeklyRecipients = $this->filterReportRecipients($admins, $now, 'weekly');
            if ($weeklyRecipients !== []) {
                [$start, $end] = $this->resolveWeekWindow($now);
                $digest = $this->reportDigestBuilder->buildWeekly($business, $start, $end);
                $this->sendReportToRecipients('REPORT_WEEKLY', 'Reporte semanal', 'emails/reports/report_weekly.html.twig', $digest, $business, $weeklyRecipients, $dryRun, $output, $counts);
            }

            $monthlyRecipients = $this->filterReportRecipients($admins, $now, 'monthly');
            if ($monthlyRecipients !== []) {
                [$start, $end] = $this->resolveMonthWindow($now);
                $digest = $this->reportDigestBuilder->buildMonthly($business, $start, $end);
                $this->sendReportToRecipients('REPORT_MONTHLY', 'Reporte mensual', 'emails/reports/report_monthly.html.twig', $digest, $business, $monthlyRecipients, $dryRun, $output, $counts);
            }

            $annualRecipients = $this->filterReportRecipients($admins, $now, 'annual');
            if ($annualRecipients !== []) {
                [$start, $end] = $this->resolveYearWindow($now);
                $digest = $this->reportDigestBuilder->buildAnnual($business, $start, $end);
                $this->sendReportToRecipients('REPORT_ANNUAL', 'Reporte anual', 'emails/reports/report_annual.html.twig', $digest, $business, $annualRecipients, $dryRun, $output, $counts);
            }
        }
    }

    private function dispatchPlatformDigests(\DateTimeImmutable $now, bool $dryRun, OutputInterface $output, array &$counts): void
    {
        if ($this->isWeeklyMoment($now)) {
            [$start, $end] = $this->resolveWeekWindow($now);
            $this->dispatchPlatformDigest('PLATFORM_DIGEST_WEEKLY', $start, $end, $dryRun, $output, $counts);
        }

        if ($this->isMonthlyMoment($now)) {
            [$start, $end] = $this->resolveMonthWindow($now);
            $this->dispatchPlatformDigest('PLATFORM_DIGEST_MONTHLY', $start, $end, $dryRun, $output, $counts);
        }
    }

    private function dispatchPlatformDigest(
        string $type,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        bool $dryRun,
        OutputInterface $output,
        array &$counts,
    ): void {
        if ($dryRun) {
            $this->recordSkip($counts, $type, 'dry-run');
            $output->writeln(sprintf('[%s] SKIPPED dry-run', $type));

            return;
        }

        $statuses = match ($type) {
            'PLATFORM_DIGEST_WEEKLY' => $this->platformNotificationService->sendWeeklyPlatformDigest($start, $end),
            'PLATFORM_DIGEST_MONTHLY' => $this->platformNotificationService->sendMonthlyPlatformDigest($start, $end),
            default => [],
        };

        $this->mergeCounts($counts, $type, $statuses);
    }

    private function sendReportToRecipients(
        string $type,
        string $subject,
        string $template,
        ReportDigest $digest,
        Business $business,
        array $recipients,
        bool $dryRun,
        OutputInterface $output,
        array &$counts,
    ): void {
        foreach ($recipients as $user) {
            if (!$user instanceof User) {
                continue;
            }

            if ($dryRun) {
                $this->recordSkip($counts, $type, 'dry-run');
                $output->writeln(sprintf('[%s] SKIPPED dry-run for %s', $type, $user->getUserIdentifier()));
                continue;
            }

            $context = $this->reportNotificationService->buildReportContext($digest, $user);
            $status = $this->emailSender->sendTemplatedEmail(
                $type,
                $user->getEmail() ?? $user->getUserIdentifier(),
                'ADMIN',
                $subject,
                $template,
                $context,
                $business,
                $business->getSubscription(),
                $digest->getPeriodStart(),
                $digest->getPeriodEnd(),
            );

            $this->recordStatus($counts, $type, $status);
        }
    }

    private function handleSubscriptionRule(
        Subscription $subscription,
        string $type,
        string $subject,
        string $template,
        \DateTimeImmutable $now,
        bool $dryRun,
        array &$counts,
        OutputInterface $output,
        \Closure $windowResolver,
        \Closure $contextResolver,
    ): void {
        $window = $windowResolver($subscription, $now);
        if ($window === null) {
            return;
        }

        $business = $subscription->getBusiness();
        if (!$business instanceof Business) {
            return;
        }

        foreach ($this->getAdminUsers($business) as $user) {
            $preference = $this->emailPreferenceRepository->getEffectivePreference($business, $user);
            if (!$this->canSendSubscription($preference)) {
                $this->recordSkip($counts, $type, 'preferences_disabled');
                $output->writeln(sprintf('[%s] SKIPPED preferences for %s', $type, $user->getUserIdentifier()));
                continue;
            }

            $context = array_merge(($contextResolver)($subscription), [
                'userName' => $user->getFullName() ?? $user->getUserIdentifier(),
                'businessName' => $business->getName(),
            ]);

            if ($dryRun) {
                $this->recordSkip($counts, $type, 'dry-run');
                $output->writeln(sprintf('[%s] SKIPPED dry-run for %s', $type, $user->getUserIdentifier()));
                continue;
            }

            $status = $this->emailSender->sendTemplatedEmail(
                $type,
                $user->getEmail() ?? $user->getUserIdentifier(),
                'ADMIN',
                $subject,
                $template,
                $context,
                $business,
                $subscription,
                $window['periodStart'],
                $window['periodEnd'],
            );

            $this->recordStatus($counts, $type, $status);
        }
    }

    private function filterReportRecipients(array $admins, \DateTimeImmutable $now, string $type): array
    {
        $recipients = [];

        foreach ($admins as $user) {
            if (!$user instanceof User) {
                continue;
            }

            $business = $user->getBusiness();
            if (!$business instanceof Business) {
                continue;
            }

            $preference = $this->emailPreferenceRepository->getEffectivePreference($business, $user);
            if (!$preference->isEnabled()) {
                continue;
            }

            if (!$this->isReportEnabledForPreference($preference, $type)) {
                continue;
            }

            if (!$this->isScheduledMoment($preference, $now, $type)) {
                continue;
            }

            $recipients[] = $user;
        }

        return $recipients;
    }

    private function isReportEnabledForPreference(EmailPreference $preference, string $type): bool
    {
        return match ($type) {
            'daily' => $preference->isReportDailyEnabled(),
            'weekly' => $preference->isReportWeeklyEnabled(),
            'monthly' => $preference->isReportMonthlyEnabled(),
            'annual' => $preference->isReportAnnualEnabled(),
            default => false,
        };
    }

    private function isScheduledMoment(EmailPreference $preference, \DateTimeImmutable $now, string $type): bool
    {
        $timezone = new \DateTimeZone($preference->getTimezone());
        $localNow = $now->setTimezone($timezone);

        if ((int) $localNow->format('H') !== $preference->getDeliveryHour()) {
            return false;
        }

        if ((int) $localNow->format('i') !== $preference->getDeliveryMinute()) {
            return false;
        }

        return match ($type) {
            'daily' => true,
            'weekly' => $localNow->format('N') === '1',
            'monthly' => $localNow->format('j') === '1',
            'annual' => $localNow->format('z') === '0',
            default => false,
        };
    }

    private function isWeeklyMoment(\DateTimeImmutable $now): bool
    {
        return $now->format('N') === '1';
    }

    private function isMonthlyMoment(\DateTimeImmutable $now): bool
    {
        return $now->format('j') === '1';
    }

    private function isAnnualMoment(\DateTimeImmutable $now): bool
    {
        return $now->format('z') === '0';
    }

    private function resolveWeekWindow(\DateTimeImmutable $now): array
    {
        $start = $now->modify('monday this week')->setTime(0, 0, 0);
        $end = $now->modify('sunday this week')->setTime(23, 59, 59);

        return [$start, $end];
    }

    private function resolveMonthWindow(\DateTimeImmutable $now): array
    {
        $start = $now->modify('first day of this month')->setTime(0, 0, 0);
        $end = $now->modify('last day of this month')->setTime(23, 59, 59);

        return [$start, $end];
    }

    private function resolveYearWindow(\DateTimeImmutable $now): array
    {
        $start = $now->setDate((int) $now->format('Y'), 1, 1)->setTime(0, 0, 0);
        $end = $now->setDate((int) $now->format('Y'), 12, 31)->setTime(23, 59, 59);

        return [$start, $end];
    }

    private function trialExpiringWindow(Subscription $subscription, \DateTimeImmutable $now): ?array
    {
        $trialEndsAt = $subscription->getTrialEndsAt();
        if ($trialEndsAt === null) {
            return null;
        }

        $windowStart = $now->modify('+3 days');
        $windowEnd = $now->modify('+4 days');

        if ($trialEndsAt < $windowStart || $trialEndsAt >= $windowEnd) {
            return null;
        }

        return [
            'periodStart' => $trialEndsAt->setTime(0, 0, 0),
            'periodEnd' => $trialEndsAt->setTime(23, 59, 59),
        ];
    }

    private function trialExpiredWindow(Subscription $subscription, \DateTimeImmutable $now): ?array
    {
        $trialEndsAt = $subscription->getTrialEndsAt();
        if ($trialEndsAt === null || $trialEndsAt >= $now) {
            return null;
        }

        return [
            'periodStart' => $trialEndsAt->setTime(0, 0, 0),
            'periodEnd' => $trialEndsAt->setTime(23, 59, 59),
        ];
    }

    private function nextChargeWindow(Subscription $subscription, \DateTimeImmutable $now): ?array
    {
        $nextChargeAt = $subscription->getNextPaymentAt() ?? $subscription->getEndAt();
        if ($nextChargeAt === null) {
            return null;
        }

        $windowStart = $now->modify('+7 days');
        $windowEnd = $now->modify('+8 days');

        if ($nextChargeAt < $windowStart || $nextChargeAt >= $windowEnd) {
            return null;
        }

        return [
            'periodStart' => $nextChargeAt->setTime(0, 0, 0),
            'periodEnd' => $nextChargeAt->setTime(23, 59, 59),
        ];
    }

    private function endAtWindow(Subscription $subscription, \DateTimeImmutable $now): ?array
    {
        $endAt = $subscription->getEndAt();
        if ($endAt === null) {
            return null;
        }

        $windowStart = $now->modify('+7 days');
        $windowEnd = $now->modify('+8 days');

        if ($endAt < $windowStart || $endAt >= $windowEnd) {
            return null;
        }

        return [
            'periodStart' => $endAt->setTime(0, 0, 0),
            'periodEnd' => $endAt->setTime(23, 59, 59),
        ];
    }

    private function noSubscriptionWindow(Subscription $subscription, \DateTimeImmutable $now): ?array
    {
        if ($subscription->getStatus() !== Subscription::STATUS_CANCELED) {
            return null;
        }

        $endAt = $subscription->getEndAt();
        if ($endAt !== null && $endAt >= $now) {
            return null;
        }

        return [
            'periodStart' => null,
            'periodEnd' => null,
        ];
    }

    /**
     * @return User[]
     */
    private function getAdminUsers(Business $business): array
    {
        return array_values(array_filter(
            $business->getUsers()->toArray(),
            static fn (User $user): bool => in_array('ROLE_ADMIN', $user->getRoles(), true)
        ));
    }

    private function canSendSubscription(EmailPreference $preference): bool
    {
        return $preference->isEnabled() && $preference->isSubscriptionAlertsEnabled();
    }

    private function recordStatus(array &$counts, string $type, string $status): void
    {
        $counts[$type] ??= [
            EmailNotificationLog::STATUS_SENT => 0,
            EmailNotificationLog::STATUS_SKIPPED => 0,
            EmailNotificationLog::STATUS_FAILED => 0,
        ];

        $counts[$type][$status] = ($counts[$type][$status] ?? 0) + 1;
    }

    private function recordSkip(array &$counts, string $type, string $reason): void
    {
        $this->recordStatus($counts, $type, EmailNotificationLog::STATUS_SKIPPED);
    }

    private function mergeCounts(array &$counts, string $type, array $statuses): void
    {
        foreach ($statuses as $status => $value) {
            $counts[$type] ??= [
                EmailNotificationLog::STATUS_SENT => 0,
                EmailNotificationLog::STATUS_SKIPPED => 0,
                EmailNotificationLog::STATUS_FAILED => 0,
            ];
            $counts[$type][$status] += $value;
        }
    }

    private function renderSummary(OutputInterface $output, array $counts): void
    {
        $output->writeln('');
        $output->writeln('Resumen de envíos:');

        foreach ($counts as $type => $values) {
            $output->writeln(sprintf(
                '- %s: enviados=%d omitidos=%d fallidos=%d',
                $type,
                $values[EmailNotificationLog::STATUS_SENT] ?? 0,
                $values[EmailNotificationLog::STATUS_SKIPPED] ?? 0,
                $values[EmailNotificationLog::STATUS_FAILED] ?? 0,
            ));
        }
    }

    private function resolveNow(mixed $dateOption): \DateTimeImmutable
    {
        $now = new \DateTimeImmutable();

        if (is_string($dateOption) && $dateOption !== '') {
            $time = $now->format('H:i:s');
            $date = new \DateTimeImmutable(sprintf('%s %s', $dateOption, $time));

            return $date;
        }

        return $now;
    }
}
