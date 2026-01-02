<?php

namespace App\Service;

use App\Entity\Business;
use App\Entity\Subscription;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;

class SubscriptionContext
{
    public function __construct(private readonly Security $security)
    {
    }

    public function getCurrentSubscription(?User $user = null): ?Subscription
    {
        $user ??= $this->security->getUser();
        if (!$user || !method_exists($user, 'getBusiness')) {
            return null;
        }

        $business = $user->getBusiness();
        if (!$business) {
            return null;
        }

        return $business->getSubscription();
    }

    public function getEffectiveSubscription(?User $user = null): ?Subscription
    {
        $user ??= $this->security->getUser();
        if (!$user || !method_exists($user, 'getBusiness')) {
            return null;
        }

        $business = $user->getBusiness();
        if (!$business) {
            return null;
        }

        return $this->getEffectiveSubscriptionForBusiness($business, new \DateTimeImmutable());
    }

    public function getEffectiveSubscriptionForBusiness(Business $business, ?\DateTimeImmutable $now = null): ?Subscription
    {
        $subscription = $business->getSubscription();
        if (!$subscription instanceof Subscription) {
            return null;
        }

        $now ??= new \DateTimeImmutable();
        if ($subscription->getStatus() === Subscription::STATUS_ACTIVE) {
            $endAt = $subscription->getEndAt();
            $nextChargeAt = $subscription->getNextPaymentAt();
            if (
                ($endAt instanceof \DateTimeImmutable && $endAt > $now)
                || ($nextChargeAt instanceof \DateTimeImmutable && $nextChargeAt > $now)
            ) {
                return $subscription;
            }
        }

        if ($subscription->getStatus() === Subscription::STATUS_TRIAL) {
            $trialEndsAt = $subscription->getTrialEndsAt();
            if ($trialEndsAt instanceof \DateTimeImmutable && $trialEndsAt > $now) {
                return $subscription;
            }
        }

        return $subscription;
    }
}
