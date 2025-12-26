<?php

namespace App\Tests\Service;

use App\Entity\Business;
use App\Entity\Subscription;
use App\Entity\User;
use App\Service\TrialSubscriptionService;
use PHPUnit\Framework\TestCase;

class TrialSubscriptionServiceTest extends TestCase
{
    public function testStartsTrialWhenMissingEndsAt(): void
    {
        $subscription = new Subscription();
        $subscription->setStatus(Subscription::STATUS_TRIAL);
        $subscription->setTrialEndsAt(null);

        $business = new Business();
        $business->setName('Demo Store');
        $business->setSubscription($subscription);

        $user = new User();
        $user->setBusiness($business);
        $user->setEmail('demo@example.com');
        $user->setFullName('Demo');
        $user->setPassword('hashed');

        $service = new TrialSubscriptionService(30);

        $started = $service->startTrialIfNeeded($user);

        self::assertTrue($started);
        self::assertNotNull($subscription->getTrialEndsAt());
    }
}
