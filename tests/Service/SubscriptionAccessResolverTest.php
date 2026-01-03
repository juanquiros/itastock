<?php

namespace App\Tests\Service;

use App\Entity\Plan;
use App\Entity\Subscription;
use App\Service\SubscriptionAccessResolver;
use PHPUnit\Framework\TestCase;

class SubscriptionAccessResolverTest extends TestCase
{
    public function testPendingSubscriptionWithFutureEndDateKeepsFullAccess(): void
    {
        $plan = (new Plan())
            ->setName('Plan')
            ->setCode('PLAN')
            ->setPriceMonthly('1000.00');
        $subscription = (new Subscription())
            ->setPlan($plan)
            ->setStatus(Subscription::STATUS_PENDING)
            ->setEndAt(new \DateTimeImmutable('+5 days'));

        $resolver = new SubscriptionAccessResolver();
        $access = $resolver->resolve($subscription);

        self::assertSame(SubscriptionAccessResolver::MODE_FULL, $access['mode']);
        self::assertSame('pending_with_validity', $access['reason']);
    }
}
