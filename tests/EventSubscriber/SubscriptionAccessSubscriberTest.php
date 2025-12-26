<?php

namespace App\Tests\EventSubscriber;

use App\Entity\Subscription;
use App\Entity\User;
use App\EventSubscriber\SubscriptionAccessSubscriber;
use App\Service\SubscriptionContext;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Routing\RouterInterface;

class SubscriptionAccessSubscriberTest extends TestCase
{
    public function testTrialExpiredRedirectsReadonlyGet(): void
    {
        $subscription = $this->createSubscription(
            Subscription::STATUS_TRIAL,
            new \DateTimeImmutable('-1 day')
        );
        $subscriber = $this->createSubscriber($subscription);
        $request = $this->createRequest('app_dashboard', Request::METHOD_GET);

        $event = $this->createRequestEvent($request);
        $subscriber->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/app/subscription/blocked', $response->headers->get('Location'));
    }

    public function testActiveAllowsRequest(): void
    {
        $subscription = $this->createSubscription(Subscription::STATUS_ACTIVE, null);
        $subscriber = $this->createSubscriber($subscription);
        $request = $this->createRequest('app_dashboard', Request::METHOD_GET);

        $event = $this->createRequestEvent($request);
        $subscriber->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testMissingSubscriptionBlocksPost(): void
    {
        $subscriber = $this->createSubscriber(null);
        $request = $this->createRequest('app_sales_new', Request::METHOD_POST);

        $event = $this->createRequestEvent($request);
        $subscriber->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(403, $response->getStatusCode());
    }

    public function testReadonlyPostReturnsJsonWhenAjax(): void
    {
        $subscription = $this->createSubscription(
            Subscription::STATUS_TRIAL,
            new \DateTimeImmutable('-1 day')
        );
        $subscriber = $this->createSubscriber($subscription);
        $request = $this->createRequest('app_sales_new', Request::METHOD_POST);
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');
        $request->headers->set('Accept', 'application/json');

        $event = $this->createRequestEvent($request);
        $subscriber->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('subscription_readonly', $response->getData()['error']);
    }

    private function createSubscriber(?Subscription $subscription): SubscriptionAccessSubscriber
    {
        $subscriptionContext = $this->createMock(SubscriptionContext::class);
        $subscriptionContext
            ->method('getCurrentSubscription')
            ->willReturn($subscription);

        $security = $this->createMock(Security::class);
        $security
            ->method('getUser')
            ->willReturn(new User());

        $router = $this->createMock(RouterInterface::class);
        $router
            ->method('generate')
            ->with('app_subscription_blocked')
            ->willReturn('/app/subscription/blocked');

        return new SubscriptionAccessSubscriber($subscriptionContext, $router, $security, 'test');
    }

    private function createRequest(string $route, string $method): Request
    {
        $request = Request::create('/app/route', $method);
        $request->attributes->set('_route', $route);

        return $request;
    }

    private function createRequestEvent(Request $request): RequestEvent
    {
        $kernel = $this->createMock(HttpKernelInterface::class);

        return new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    }

    private function createSubscription(string $status, ?\DateTimeImmutable $trialEndsAt): Subscription
    {
        $subscription = new Subscription();
        $subscription->setStatus($status);
        $subscription->setTrialEndsAt($trialEndsAt);

        return $subscription;
    }
}
