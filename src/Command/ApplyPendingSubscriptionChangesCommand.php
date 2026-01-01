<?php

namespace App\Command;

use App\Entity\PendingSubscriptionChange;
use App\Entity\Subscription;
use App\Repository\PlanRepository;
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
            ->andWhere('pendingChange.effectiveAt <= :now OR pendingChange.effectiveAt IS NULL')
            ->setParameter('status', PendingSubscriptionChange::STATUS_PAID)
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();

        if ($pendingChanges === []) {
            $output->writeln('No pending changes to apply.');

            return Command::SUCCESS;
        }

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

            $effectiveAt = $pendingChange->getEffectiveAt() ?? $now;
            $billingPlan = $pendingChange->getTargetBillingPlan();
            if (!$billingPlan) {
                continue;
            }

            $endAt = $this->calculateEndAt($effectiveAt, $billingPlan->getFrequency(), $billingPlan->getFrequencyType());
            $plan = $this->planRepository->findOneBy(['name' => $billingPlan->getName()]);
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
        }

        $this->entityManager->flush();

        $output->writeln(sprintf('Applied %d pending change(s).', count($pendingChanges)));

        return Command::SUCCESS;
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
}
