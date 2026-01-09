<?php

namespace App\Command;

use App\Entity\BillingPlan;
use App\Entity\PendingSubscriptionChange;
use App\Entity\Plan;
use App\Entity\Subscription;
use App\Repository\PlanRepository;
use App\Service\MPSubscriptionManager;
use App\Service\PlatformNotificationService;
use App\Service\SubscriptionNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:subscriptions:apply-pending-changes',
    description: 'Apply paid pending subscription changes when the effective date is reached.'
)]
class ApplyPendingSubscriptionChangesCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PlanRepository $planRepository,
        private readonly SubscriptionNotificationService $notificationService,
        private readonly PlatformNotificationService $platformNotificationService,
        private readonly MPSubscriptionManager $subscriptionManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $now = new \DateTimeImmutable();

        $pendingChanges = $this->entityManager
            ->getRepository(PendingSubscriptionChange::class)
            ->createQueryBuilder('pendingChange')
            ->andWhere('pendingChange.status = :status')
            ->setParameter('status', PendingSubscriptionChange::STATUS_PAID)
            ->getQuery()
            ->getResult();

        $expiredCount = $this->expireCheckoutStartedChanges($now);
        $appliedCount = 0;
        foreach ($pendingChanges as $pendingChange) {
            if (!$pendingChange instanceof PendingSubscriptionChange) {
                continue;
            }

            if (in_array($pendingChange->getStatus(), [
                PendingSubscriptionChange::STATUS_APPLIED,
                PendingSubscriptionChange::STATUS_CANCELED,
                PendingSubscriptionChange::STATUS_EXPIRED,
            ], true)) {
                continue;
            }

            $subscription = $pendingChange->getCurrentSubscription();
            if (!$subscription instanceof Subscription) {
                continue;
            }

            $effectiveAt = $pendingChange->getEffectiveAt()
                ?? $subscription->getEndAt()
                ?? $subscription->getNextPaymentAt()
                ?? $now;
            $currentEndAt = $subscription->getEndAt();
            if ($currentEndAt instanceof \DateTimeImmutable && $currentEndAt > $effectiveAt) {
                $effectiveAt = $currentEndAt;
            }
            $billingPlan = $pendingChange->getTargetBillingPlan();
            if (!$billingPlan) {
                continue;
            }

            $endAt = $this->calculateEndAt($effectiveAt, $billingPlan->getFrequency(), $billingPlan->getFrequencyType());
            $plan = $this->resolveTargetPlan($billingPlan, $subscription->getPlan());
            if ($plan) {
                $subscription->setPlan($plan);
            }

            $subscription
                ->setStatus(Subscription::STATUS_ACTIVE)
                ->setStartAt($effectiveAt)
                ->setEndAt($endAt)
                ->setNextPaymentAt($endAt);

            $pendingChange
                ->setStatus(PendingSubscriptionChange::STATUS_APPLIED)
                ->setAppliedAt($now);

            $this->notificationService->onSubscriptionChangeApplied($subscription, $billingPlan->getName());
            if ($subscription->getBusiness()) {
                $this->platformNotificationService->notifySubscriptionChangeApplied(
                    $subscription->getBusiness(),
                    $subscription,
                    $billingPlan->getName(),
                    $pendingChange->getAppliedAt()
                );
                $mpPreapprovalId = $pendingChange->getMpPreapprovalId();
                if (is_string($mpPreapprovalId) && $mpPreapprovalId !== '') {
                    $this->subscriptionManager->confirmNewSubscriptionActive(
                        $subscription->getBusiness(),
                        $mpPreapprovalId
                    );
                }
            }
            $appliedCount++;
        }

        $this->entityManager->flush();

        if ($appliedCount === 0 && $expiredCount === 0) {
            $output->writeln('No pending changes to apply or expire.');

            return Command::SUCCESS;
        }

        if ($appliedCount > 0) {
            $output->writeln(sprintf('Applied %d pending change(s).', $appliedCount));
        }

        if ($expiredCount > 0) {
            $output->writeln(sprintf('Expired %d pending change(s).', $expiredCount));
        }

        return Command::SUCCESS;
    }

    private function expireCheckoutStartedChanges(\DateTimeImmutable $now): int
    {
        $candidates = $this->entityManager
            ->getRepository(PendingSubscriptionChange::class)
            ->createQueryBuilder('pendingChange')
            ->andWhere('pendingChange.status = :status')
            ->setParameter('status', PendingSubscriptionChange::STATUS_CHECKOUT_STARTED)
            ->getQuery()
            ->getResult();

        $expiredCount = 0;
        foreach ($candidates as $pendingChange) {
            if (!$pendingChange instanceof PendingSubscriptionChange) {
                continue;
            }

            $subscription = $pendingChange->getCurrentSubscription();
            if (!$subscription instanceof Subscription) {
                continue;
            }

            $endAt = $subscription->getEndAt() ?? $subscription->getTrialEndsAt();
            if (!$endAt instanceof \DateTimeImmutable) {
                continue;
            }

            $graceDays = $subscription->getGracePeriodDays();
            if ($graceDays <= 0) {
                $graceDays = 3;
            }

            $expiresAt = $endAt->modify(sprintf('+%d days', $graceDays));
            if ($now <= $expiresAt) {
                continue;
            }

            $pendingChange->setStatus(PendingSubscriptionChange::STATUS_EXPIRED);
            $billingPlan = $pendingChange->getTargetBillingPlan();
            $this->notificationService->onSubscriptionChangeExpired(
                $subscription,
                $billingPlan?->getName(),
                $now
            );
            if ($subscription->getBusiness()) {
                $this->platformNotificationService->notifySubscriptionChangeExpired(
                    $subscription->getBusiness(),
                    $subscription,
                    $billingPlan?->getName(),
                    $now
                );
            }
            $expiredCount++;
        }

        return $expiredCount;
    }

    private function calculateEndAt(\DateTimeImmutable $startAt, int $frequency, string $frequencyType): \DateTimeImmutable
    {
        $normalizedType = strtolower($frequencyType);
        $unit = match ($normalizedType) {
            'month', 'months' => 'months',
            'year', 'years' => 'years',
            'day', 'days' => 'days',
            default => 'months',
        };

        $interval = sprintf('+%d %s', max(1, $frequency), $unit);

        return $startAt->modify($interval);
    }

    private function resolveTargetPlan(BillingPlan $billingPlan, ?Plan $fallback): ?Plan
    {
        $plan = $this->planRepository->findOneBy(['name' => $billingPlan->getName()])
            ?? $this->planRepository->findOneBy(['code' => $billingPlan->getName()]);
        if ($plan instanceof Plan) {
            return $plan;
        }

        $candidates = $this->planRepository->findAll();
        $frequency = max(1, $billingPlan->getFrequency());
        $normalizedType = strtolower($billingPlan->getFrequencyType());
        $monthlyAmount = null;

        if ($normalizedType === 'months' || $normalizedType === 'month') {
            $monthlyAmount = (float) $billingPlan->getPrice() / $frequency;
        }

        foreach ($candidates as $candidate) {
            if (!$candidate instanceof Plan) {
                continue;
            }

            if ($monthlyAmount !== null && $candidate->getPriceMonthly() !== null) {
                $candidateAmount = (float) $candidate->getPriceMonthly();
                if (abs($candidateAmount - $monthlyAmount) < 0.01) {
                    return $candidate;
                }
            }
        }

        return $fallback;
    }
}
