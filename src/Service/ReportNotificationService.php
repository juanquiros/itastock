<?php

namespace App\Service;

use App\DTO\ReportDigest;
use App\Entity\Business;
use App\Entity\BusinessUser;
use App\Entity\User;
use App\Entity\EmailPreference;
use App\Repository\EmailPreferenceRepository;

class ReportNotificationService
{
    public function __construct(
        private readonly EmailPreferenceRepository $emailPreferenceRepository,
        private readonly ReportDigestBuilder $reportDigestBuilder,
        private readonly EmailSender $emailSender,
    ) {
    }

    public function sendDailyIfEnabled(Business $business, \DateTimeImmutable $date): void
    {
        $digest = $this->reportDigestBuilder->buildDaily($business, $date);
        $this->sendIfEnabled($business, $digest, 'REPORT_DAILY', 'emails/reports/report_daily.html.twig', $digest->getPeriodStart(), $digest->getPeriodEnd());
    }

    public function sendWeeklyIfEnabled(Business $business, \DateTimeImmutable $start, \DateTimeImmutable $end): void
    {
        $digest = $this->reportDigestBuilder->buildWeekly($business, $start, $end);
        $this->sendIfEnabled($business, $digest, 'REPORT_WEEKLY', 'emails/reports/report_weekly.html.twig', $start, $end);
    }

    public function sendMonthlyIfEnabled(Business $business, \DateTimeImmutable $start, \DateTimeImmutable $end): void
    {
        $digest = $this->reportDigestBuilder->buildMonthly($business, $start, $end);
        $this->sendIfEnabled($business, $digest, 'REPORT_MONTHLY', 'emails/reports/report_monthly.html.twig', $start, $end);
    }

    public function sendAnnualIfEnabled(Business $business, \DateTimeImmutable $start, \DateTimeImmutable $end): void
    {
        $digest = $this->reportDigestBuilder->buildAnnual($business, $start, $end);
        $this->sendIfEnabled($business, $digest, 'REPORT_ANNUAL', 'emails/reports/report_annual.html.twig', $start, $end);
    }

    public function buildReportContext(ReportDigest $digest, User $user): array
    {
        $salesCount = $digest->getSalesCount();
        $salesTotal = $digest->getSalesTotal();
        $averageTicket = null;
        if ($salesCount && $salesTotal !== null && $salesCount > 0) {
            $averageTicket = round($salesTotal / $salesCount, 2);
        }

        $lowStock = $digest->getLowStock();

        return [
            'userName' => $user->getFullName() ?? $user->getUserIdentifier(),
            'businessName' => $digest->getBusinessName(),
            'periodStart' => $digest->getPeriodStart(),
            'periodEnd' => $digest->getPeriodEnd(),
            'totalSales' => $salesTotal,
            'totalOrders' => $salesCount,
            'averageTicket' => $averageTicket,
            'debtorsCount' => $digest->getDebtorsCount(),
            'lowStockCount' => is_array($lowStock) ? count($lowStock) : null,
            'notes' => $digest->getNotes(),
        ];
    }

    private function sendIfEnabled(
        Business $business,
        ReportDigest $digest,
        string $type,
        string $template,
        \DateTimeImmutable $periodStart,
        \DateTimeImmutable $periodEnd,
    ): void {
        $subject = match ($type) {
            'REPORT_DAILY' => 'Reporte diario',
            'REPORT_WEEKLY' => 'Reporte semanal',
            'REPORT_MONTHLY' => 'Reporte mensual',
            'REPORT_ANNUAL' => 'Reporte anual',
            default => 'Reporte',
        };

        foreach ($business->getBusinessUsers() as $membership) {
            $user = $membership->getUser();
            if (
                !$user instanceof User
                || !$membership->isActive()
                || !in_array($membership->getRole(), [BusinessUser::ROLE_OWNER, BusinessUser::ROLE_ADMIN], true)
            ) {
                continue;
            }

            $preference = $this->emailPreferenceRepository->getEffectivePreference($business, $user);
            if (!$preference->isEnabled()) {
                continue;
            }

            if (!$this->isTypeEnabled($preference, $type)) {
                continue;
            }

            $context = $this->buildReportContext($digest, $user);

            $this->emailSender->sendTemplatedEmail(
                $type,
                $user->getEmail() ?? $user->getUserIdentifier(),
                'ADMIN',
                $subject,
                $template,
                $context,
                $business,
                $business->getSubscription(),
                $periodStart,
                $periodEnd,
            );
        }
    }

    private function isTypeEnabled(EmailPreference $preference, string $type): bool
    {
        return match ($type) {
            'REPORT_DAILY' => $preference->isReportDailyEnabled(),
            'REPORT_WEEKLY' => $preference->isReportWeeklyEnabled(),
            'REPORT_MONTHLY' => $preference->isReportMonthlyEnabled(),
            'REPORT_ANNUAL' => $preference->isReportAnnualEnabled(),
            default => false,
        };
    }

    private function buildContext(ReportDigest $digest, User $user): array
    {
        return $this->buildReportContext($digest, $user);
    }
}
