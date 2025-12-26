<?php

namespace App\Service;

use App\Entity\Subscription;
use App\Entity\User;

class TrialSubscriptionService
{
    public function __construct(private readonly int $trialDurationDays)
    {
    }

    public function startTrialIfNeeded(User $user): bool
    {
        $business = $user->getBusiness();
        if (!$business) {
            return false;
        }

        $subscription = $business->getSubscription();
        if (!$subscription) {
            return false;
        }

        if ($subscription->getStatus() !== Subscription::STATUS_TRIAL) {
            return false;
        }

        if ($subscription->getTrialEndsAt() !== null) {
            return false;
        }

        $subscription->setTrialEndsAt(
            (new \DateTimeImmutable())->modify(sprintf('+%d days', $this->trialDurationDays))
        );

        return true;
    }
}
