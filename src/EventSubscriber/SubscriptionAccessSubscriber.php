<?php

namespace App\EventSubscriber;

use App\Entity\Subscription;
use App\Service\SubscriptionContext;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;

class SubscriptionAccessSubscriber implements EventSubscriberInterface
{
    public const MODE_FULL = 'FULL';
    public const MODE_READONLY = 'READONLY';
    public const MODE_BLOCKED = 'BLOCKED';

    private const BYPASS_ROUTES = [
        'public_home',
        'public_features',
        'public_pricing',
        'public_contact',
        'public_terms',
        'public_privacy',
        'public_page',
        'app_login_root',
        'app_login',
        'app_logout',
        'app_password_request',
        'app_password_reset',
        'app_subscription_blocked',
        '_wdt',
        '_profiler',
    ];

    private const READONLY_ALLOWED_ROUTES = [
        'app_reports_index',
        'app_reports_debtors',
        'app_reports_debtors_pdf',
        'app_reports_stock_low_pdf',
        'app_product_export',
        'app_customer_account_export',
        'app_customer_account_pdf',
        'app_cash_report',
        'app_admin_sales_export',
        'app_admin_sales_pdf',
        'app_admin_cash_pdf',
        'app_subscription_blocked',
        'app_logout',
    ];

    public function __construct(
        private readonly SubscriptionContext $subscriptionContext,
        private readonly RouterInterface $router,
        private readonly Security $security,
        private readonly string $environment,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $route = (string) $request->attributes->get('_route');

        if ($this->shouldBypass($route)) {
            return;
        }

        if (str_starts_with($route, 'platform_')) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user) {
            return;
        }

        $subscription = $this->subscriptionContext->getCurrentSubscription($user);
        $mode = $this->resolveMode($subscription);
        $request->attributes->set('_subscription_access_mode', $mode);

        if ($mode === self::MODE_FULL) {
            return;
        }

        if ($mode === self::MODE_READONLY && in_array($route, self::READONLY_ALLOWED_ROUTES, true)) {
            return;
        }

        if ($this->shouldReturnForbidden($request)) {
            $event->setResponse($this->buildForbiddenResponse($request, $mode));

            return;
        }

        $this->addReadonlyFlash($request, $mode);
        $event->setResponse(new RedirectResponse($this->router->generate('app_subscription_blocked')));
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if ($this->environment !== 'dev') {
            return;
        }

        $request = $event->getRequest();
        $mode = $request->attributes->get('_subscription_access_mode');
        if (!$mode) {
            return;
        }

        $event->getResponse()->headers->set('X-Subscription-Mode', (string) $mode);
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => 'onKernelRequest',
            KernelEvents::RESPONSE => 'onKernelResponse',
        ];
    }

    private function shouldBypass(string $route): bool
    {
        if ($route === '') {
            return true;
        }

        if (str_starts_with($route, 'web_profiler_')) {
            return true;
        }

        return in_array($route, self::BYPASS_ROUTES, true);
    }

    private function resolveMode(?Subscription $subscription): string
    {
        if (!$subscription) {
            return self::MODE_BLOCKED;
        }

        $status = $subscription->getStatus();
        if ($status === Subscription::STATUS_TRIAL) {
            $trialEndsAt = $subscription->getTrialEndsAt();
            if (!$trialEndsAt) {
                return self::MODE_BLOCKED;
            }

            return $trialEndsAt > new \DateTimeImmutable() ? self::MODE_FULL : self::MODE_READONLY;
        }

        if ($status === Subscription::STATUS_ACTIVE) {
            return self::MODE_FULL;
        }

        // For past-due or canceled states, default to READONLY even if endAt is missing.
        if (in_array(
            $status,
            [Subscription::STATUS_PAST_DUE, Subscription::STATUS_SUSPENDED, Subscription::STATUS_CANCELED],
            true
        )) {
            return self::MODE_READONLY;
        }

        return self::MODE_BLOCKED;
    }

    private function shouldReturnForbidden(Request $request): bool
    {
        if ($request->isXmlHttpRequest()) {
            return true;
        }

        return $request->getMethod() !== Request::METHOD_GET;
    }

    private function buildForbiddenResponse(Request $request, string $mode): Response
    {
        if ($request->isXmlHttpRequest() || str_contains((string) $request->headers->get('Accept'), 'application/json')) {
            return new JsonResponse(
                [
                    'error' => $mode === self::MODE_READONLY ? 'subscription_readonly' : 'subscription_blocked',
                    'message' => $mode === self::MODE_READONLY
                        ? 'La cuenta está en modo solo lectura.'
                        : 'La cuenta está bloqueada.',
                ],
                Response::HTTP_FORBIDDEN
            );
        }

        return new Response('Forbidden', Response::HTTP_FORBIDDEN);
    }

    private function addReadonlyFlash(Request $request, string $mode): void
    {
        if ($mode !== self::MODE_READONLY) {
            return;
        }

        if (!$request->hasSession()) {
            return;
        }

        $request->getSession()->getFlashBag()->add(
            'warning',
            'Cuenta en modo solo lectura. Renová para reactivar funciones.'
        );
    }
}
