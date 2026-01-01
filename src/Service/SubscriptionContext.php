<?php

namespace App\Service;

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
        return $this->getCurrentSubscription($user);
    }
}
