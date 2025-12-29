<?php

namespace App\Controller;

use App\Entity\BillingPlan;
use App\Entity\Subscription;
use App\Exception\MercadoPagoApiException;
use App\Repository\BillingPlanRepository;
use App\Service\MercadoPagoClient;
use App\Service\SubscriptionAccessResolver;
use App\Service\SubscriptionContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class BillingSubscriptionController extends AbstractController
{
    #[Route('/app/billing/subscription', name: 'app_billing_subscription_show', methods: ['GET'])]
    public function show(
        SubscriptionContext $subscriptionContext,
        SubscriptionAccessResolver $accessResolver,
        BillingPlanRepository $billingPlanRepository,
    ): Response {
        $subscription = $subscriptionContext->getCurrentSubscription($this->getUser());
        $access = $accessResolver->resolve($subscription);

        return $this->render('subscription/show.html.twig', [
            'subscription' => $subscription,
            'access' => $access,
            'billingPlans' => $billingPlanRepository->findBy(['isActive' => true], ['price' => 'ASC', 'name' => 'ASC']),
        ]);
    }

    #[Route('/app/billing/subscription/choose/{id}', name: 'app_billing_subscription_choose', methods: ['POST'])]
    public function choose(
        BillingPlan $billingPlan,
        Request $request,
        SubscriptionContext $subscriptionContext,
        MercadoPagoClient $mercadoPagoClient,
        EntityManagerInterface $entityManager,
        string $mercadoPagoMode,
    ): RedirectResponse {
        if (!$this->isCsrfTokenValid('choose_billing_plan_'.$billingPlan->getId(), (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('app_billing_subscription_show');
        }

        if (!$billingPlan->isActive()) {
            $this->addFlash('warning', 'El plan seleccionado no está activo.');

            return $this->redirectToRoute('app_billing_subscription_show');
        }

        if ($billingPlan->getMpPreapprovalPlanId() === null) {
            $this->addFlash('warning', 'El plan seleccionado todavía no está sincronizado con Mercado Pago.');

            return $this->redirectToRoute('app_billing_subscription_show');
        }

        $subscription = $subscriptionContext->getCurrentSubscription($this->getUser());
        if (!$subscription) {
            $this->addFlash('danger', 'No se encontró una suscripción asociada al comercio.');

            return $this->redirectToRoute('app_billing_subscription_show');
        }

        $payerEmail = $this->getUser()?->getUserIdentifier();

        try {
            $response = $mercadoPagoClient->createPreapprovalCheckout([
                'preapproval_plan_id' => $billingPlan->getMpPreapprovalPlanId(),
                'reason' => $billingPlan->getName(),
                'payer_email' => $payerEmail,
                'external_reference' => (string) $subscription->getId(),
                'back_url' => $this->generateUrl('app_billing_return', [], UrlGeneratorInterface::ABSOLUTE_URL),
            ]);
        } catch (MercadoPagoApiException $exception) {
            $this->addFlash('danger', sprintf('Error al iniciar la suscripción en Mercado Pago: %s', $exception->getMessage()));

            return $this->redirectToRoute('app_billing_subscription_show');
        }

        if (!isset($response['id'])) {
            $this->addFlash('danger', 'Mercado Pago no devolvió un identificador de preapproval.');

            return $this->redirectToRoute('app_billing_subscription_show');
        }

        $initPoint = $mercadoPagoMode === 'sandbox'
            ? ($response['sandbox_init_point'] ?? null)
            : ($response['init_point'] ?? null);

        if (!$initPoint) {
            $this->addFlash('danger', 'Mercado Pago no devolvió un link de pago.');

            return $this->redirectToRoute('app_billing_subscription_show');
        }

        $subscription
            ->setMpPreapprovalId((string) $response['id'])
            ->setMpPreapprovalPlanId($billingPlan->getMpPreapprovalPlanId())
            ->setPayerEmail($payerEmail)
            ->setLastSyncedAt(new \DateTimeImmutable())
            ->setStatus(Subscription::STATUS_PENDING)
            ->setTrialEndsAt(null);

        $entityManager->flush();

        return new RedirectResponse($initPoint);
    }

    #[Route('/app/billing/subscription/pause', name: 'app_billing_pause', methods: ['POST'])]
    public function pause(
        Request $request,
        SubscriptionContext $subscriptionContext,
        MercadoPagoClient $mercadoPagoClient,
        EntityManagerInterface $entityManager,
    ): RedirectResponse {
        return $this->handleStatusChange($request, $subscriptionContext, $mercadoPagoClient, $entityManager, 'paused', 'Suscripción pausada.');
    }

    #[Route('/app/billing/subscription/reactivate', name: 'app_billing_reactivate', methods: ['POST'])]
    public function reactivate(
        Request $request,
        SubscriptionContext $subscriptionContext,
        MercadoPagoClient $mercadoPagoClient,
        EntityManagerInterface $entityManager,
    ): RedirectResponse {
        return $this->handleStatusChange($request, $subscriptionContext, $mercadoPagoClient, $entityManager, 'authorized', 'Suscripción reactivada.');
    }

    #[Route('/app/billing/subscription/cancel', name: 'app_billing_cancel', methods: ['POST'])]
    public function cancel(
        Request $request,
        SubscriptionContext $subscriptionContext,
        MercadoPagoClient $mercadoPagoClient,
        EntityManagerInterface $entityManager,
    ): RedirectResponse {
        return $this->handleStatusChange($request, $subscriptionContext, $mercadoPagoClient, $entityManager, 'cancelled', 'Suscripción cancelada.');
    }

    #[Route('/app/billing/return', name: 'app_billing_return', methods: ['GET'])]
    public function billingReturn(): Response
    {
        return $this->render('subscription/return.html.twig');
    }

    private function handleStatusChange(
        Request $request,
        SubscriptionContext $subscriptionContext,
        MercadoPagoClient $mercadoPagoClient,
        EntityManagerInterface $entityManager,
        string $targetStatus,
        string $successMessage,
    ): RedirectResponse {
        if (!$this->isCsrfTokenValid('billing_subscription_action', (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('app_billing_subscription_show');
        }

        $subscription = $subscriptionContext->getCurrentSubscription($this->getUser());
        if (!$subscription || !$subscription->getMpPreapprovalId()) {
            $this->addFlash('warning', 'No hay una suscripción activa para gestionar.');

            return $this->redirectToRoute('app_billing_subscription_show');
        }

        try {
            if ($targetStatus === 'cancelled') {
                $mercadoPagoClient->cancelPreapproval($subscription->getMpPreapprovalId());
            } else {
                $mercadoPagoClient->updatePreapproval($subscription->getMpPreapprovalId(), ['status' => $targetStatus]);
            }

            $preapproval = $mercadoPagoClient->getPreapproval($subscription->getMpPreapprovalId());
            $this->applyPreapprovalToSubscription($subscription, $preapproval);
            $entityManager->flush();
            $this->addFlash('success', $successMessage);
        } catch (MercadoPagoApiException $exception) {
            $this->addFlash('danger', sprintf('No pudimos actualizar la suscripción: %s', $exception->getMessage()));
        }

        return $this->redirectToRoute('app_billing_subscription_show');
    }

    private function applyPreapprovalToSubscription(Subscription $subscription, array $preapproval): void
    {
        $status = $preapproval['status'] ?? null;
        $mappedStatus = match ($status) {
            'authorized', 'active' => Subscription::STATUS_ACTIVE,
            'paused', 'suspended' => Subscription::STATUS_SUSPENDED,
            'past_due' => Subscription::STATUS_PAST_DUE,
            'cancelled', 'canceled' => Subscription::STATUS_CANCELED,
            default => Subscription::STATUS_PENDING,
        };

        $subscription
            ->setStatus($mappedStatus)
            ->setMpPreapprovalPlanId($preapproval['preapproval_plan_id'] ?? $subscription->getMpPreapprovalPlanId())
            ->setPayerEmail($preapproval['payer_email'] ?? $subscription->getPayerEmail())
            ->setLastSyncedAt(new \DateTimeImmutable());
    }
}
