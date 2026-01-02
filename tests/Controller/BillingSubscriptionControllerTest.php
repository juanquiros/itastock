<?php

namespace App\Tests\Controller;

use App\Controller\BillingSubscriptionController;
use App\Entity\BillingPlan;
use App\Entity\Business;
use App\Entity\Plan;
use App\Entity\Subscription;
use App\Entity\User;
use App\Service\MercadoPagoClient;
use App\Service\PlatformNotificationService;
use App\Service\SubscriptionContext;
use App\Service\SubscriptionNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

class BillingSubscriptionControllerTest extends TestCase
{
    public function testChooseKeepsActiveSubscriptionFull(): void
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

        $user = (new User())
            ->setEmail('admin@example.com')
            ->setFullName('Admin')
            ->setBusiness($business);

        $billingPlan = (new BillingPlan())
            ->setName('Anual')
            ->setPrice('12000.00')
            ->setCurrency('ARS')
            ->setFrequency(12)
            ->setFrequencyType('months')
            ->setIsActive(true)
            ->setMpPreapprovalPlanId('plan_123');

        $request = new Request([], ['_token' => 'token']);

        $subscriptionContext = $this->createMock(SubscriptionContext::class);
        $subscriptionContext
            ->method('getCurrentSubscription')
            ->willReturn($subscription);

        $mercadoPagoClient = $this->createMock(MercadoPagoClient::class);
        $mercadoPagoClient
            ->method('createPreapproval')
            ->willReturn([
                'id' => 'preapproval_123',
                'init_point' => 'https://pay.test/init',
            ]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->method('getRepository')
            ->willReturn(new PendingChangeRepositoryStub());
        $entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::callback(static function ($entity): bool {
                return $entity instanceof \App\Entity\PendingSubscriptionChange;
            }));
        $entityManager
            ->expects(self::once())
            ->method('flush');

        $subscriptionNotificationService = $this->createMock(SubscriptionNotificationService::class);
        $subscriptionNotificationService
            ->expects(self::once())
            ->method('onSubscriptionChangeScheduled');

        $platformNotificationService = $this->createMock(PlatformNotificationService::class);
        $platformNotificationService
            ->expects(self::once())
            ->method('notifySubscriptionChangeScheduled');

        $controller = new TestBillingSubscriptionController($user);
        $response = $controller->choose(
            $billingPlan,
            $request,
            $subscriptionContext,
            $mercadoPagoClient,
            $entityManager,
            $subscriptionNotificationService,
            $platformNotificationService,
            'sandbox'
        );

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('https://pay.test/init', $response->getTargetUrl());
        self::assertSame(Subscription::STATUS_ACTIVE, $subscription->getStatus());
    }
}

class PendingChangeRepositoryStub
{
    public function createQueryBuilder(string $alias): PendingChangeQueryBuilderStub
    {
        return new PendingChangeQueryBuilderStub();
    }
}

class PendingChangeQueryBuilderStub
{
    public function andWhere(string $where): self
    {
        return $this;
    }

    public function setParameter(string $name, mixed $value): self
    {
        return $this;
    }

    public function setMaxResults(int $maxResults): self
    {
        return $this;
    }

    public function getQuery(): self
    {
        return $this;
    }

    public function getOneOrNullResult(): mixed
    {
        return null;
    }
}

class TestBillingSubscriptionController extends BillingSubscriptionController
{
    public function __construct(private readonly User $user)
    {
    }

    protected function getUser(): ?User
    {
        return $this->user;
    }

    protected function isCsrfTokenValid(string $id, ?string $token): bool
    {
        return true;
    }

    protected function addFlash(string $type, mixed $message): void
    {
    }

    protected function generateUrl(string $route, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
    {
        return 'http://example.test/return';
    }

    protected function redirectToRoute(string $route, array $parameters = [], int $status = 302): RedirectResponse
    {
        return new RedirectResponse('/app/billing/subscription', $status);
    }
}
