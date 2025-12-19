<?php

namespace App\EventSubscriber;

use App\Entity\Subscription;
use App\Service\SubscriptionContext;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Bundle\SecurityBundle\Security;

class SubscriptionStatusSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly SubscriptionContext $subscriptionContext,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly RouterInterface $router,
        private readonly Security $security,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();

        if (!str_starts_with($path, '/app')) {
            return;
        }

        $route = (string) $request->attributes->get('_route');
        if (in_array($route, ['app_login', 'app_logout', 'app_subscription_blocked'], true)) {
            return;
        }

        if ($this->authorizationChecker->isGranted('ROLE_PLATFORM_ADMIN')) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user) {
            return;
        }

        if (method_exists($user, 'isActive') && !$user->isActive()) {
            $event->setResponse(new RedirectResponse($this->router->generate('app_subscription_blocked')));

            return;
        }

        $subscription = $this->subscriptionContext->getCurrentSubscription($user);
        if (!$subscription) {
            $event->setResponse(new RedirectResponse($this->router->generate('app_subscription_blocked')));

            return;
        }

        if (!in_array($subscription->getStatus(), [Subscription::STATUS_TRIAL, Subscription::STATUS_ACTIVE], true)) {
            $event->setResponse(new RedirectResponse($this->router->generate('app_subscription_blocked')));
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'kernel.request' => 'onKernelRequest',
        ];
    }
}
