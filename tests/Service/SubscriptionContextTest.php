<?php

namespace App\Tests\Service;

use App\Entity\Business;
use App\Entity\Plan;
use App\Entity\Subscription;
use App\Service\SubscriptionContext;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

class SubscriptionContextTest extends TestCase
{
    public function testGetEffectiveSubscriptionForBusinessPrefersActive(): void
    {
        $business = (new Business())->setName('Demo');
        $plan = (new Plan())
            ->setName('Mensual')
            ->setCode('MENSUAL')
            ->setPriceMonthly('1000.00');
        $subscription = (new Subscription())
            ->setBusiness($business)
            ->setPlan($plan)
            ->setStatus(Subscription::STATUS_ACTIVE)
            ->setEndAt(new \DateTimeImmutable('+20 days'));
        $business->setSubscription($subscription);

        $context = new SubscriptionContext($this->createMock(Security::class));
        $effective = $context->getEffectiveSubscriptionForBusiness($business, new \DateTimeImmutable());

        self::assertSame($subscription, $effective);
    }
}
