<?php

namespace App\Controller;

use App\Entity\BillingPlan;
use App\Entity\MercadoPagoSubscriptionLink;
use App\Entity\PendingSubscriptionChange;
use App\Entity\Subscription;
use App\Exception\MercadoPagoApiException;
use App\Repository\MercadoPagoSubscriptionLinkRepository;
use App\Repository\BillingPlanRepository;
use App\Service\MercadoPagoClient;
use App\Service\MPSubscriptionManager;
use App\Service\PlatformNotificationService;
use App\Service\SubscriptionAccessResolver;
use App\Service\SubscriptionContext;
use App\Service\SubscriptionNotificationService;
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
        EntityManagerInterface $entityManager,
    ): Response {
        $subscription = $subscriptionContext->getCurrentSubscription($this->getUser());
        $access = $accessResolver->resolve($subscription);
        $pendingChange = null;

        if ($subscription && $subscription->getBusiness()) {
            $pendingChange = $entityManager->getRepository(PendingSubscriptionChange::class)
                ->createQueryBuilder('pendingChange')
                ->andWhere('pendingChange.business = :business')
                ->andWhere('pendingChange.status IN (:statuses)')
                ->setParameter('business', $subscription->getBusiness())
                ->setParameter('statuses', [
                    PendingSubscriptionChange::STATUS_CREATED,
                    PendingSubscriptionChange::STATUS_CHECKOUT_STARTED,
                    PendingSubscriptionChange::STATUS_PAID,
                ])
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();
        }

        return $this->render('subscription/show.html.twig', [
            'subscription' => $subscription,
            'access' => $access,
            'billingPlans' => $billingPlanRepository->findBy(['isActive' => true], ['price' => 'ASC', 'name' => 'ASC']),
            'pendingChange' => $pendingChange,
        ]);
    }

    #[Route('/app/billing/subscription/choose/{id}', name: 'app_billing_subscription_choose', methods: ['POST'])]
    public function choose(
        BillingPlan $billingPlan,
        Request $request,
        SubscriptionContext $subscriptionContext,
        MercadoPagoClient $mercadoPagoClient,
        MercadoPagoSubscriptionLinkRepository $subscriptionLinkRepository,
        EntityManagerInterface $entityManager,
        SubscriptionNotificationService $subscriptionNotificationService,
        PlatformNotificationService $platformNotificationService,
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
        $pendingChange = $entityManager->getRepository(PendingSubscriptionChange::class)
            ->createQueryBuilder('pendingChange')
            ->andWhere('pendingChange.business = :business')
            ->andWhere('pendingChange.status IN (:statuses)')
            ->setParameter('business', $subscription->getBusiness())
            ->setParameter('statuses', [
                PendingSubscriptionChange::STATUS_CREATED,
                PendingSubscriptionChange::STATUS_CHECKOUT_STARTED,
                PendingSubscriptionChange::STATUS_PAID,
            ])
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        if ($pendingChange instanceof PendingSubscriptionChange) {
            $this->addFlash('warning', 'Ya hay un cambio de plan en curso. Finalizá o cancelá el cambio antes de elegir otro plan.');

            return $this->redirectToRoute('app_billing_subscription_show');
        }
        $businessId = $subscription->getBusiness()?->getId();
        if ($businessId === null) {
            $this->addFlash('danger', 'No se encontró un comercio asociado a la suscripción.');

            return $this->redirectToRoute('app_billing_subscription_show');
        }
        $externalReference = sprintf('business:%d', $businessId);

        $now = new \DateTimeImmutable();
        $isActiveSubscription = false;
        $activeUntil = null;
        if ($subscription->getStatus() === Subscription::STATUS_ACTIVE) {
            $endAt = $subscription->getEndAt();
            $nextChargeAt = $subscription->getNextPaymentAt();
            if ($endAt && $endAt > $now) {
                $isActiveSubscription = true;
                $activeUntil = $endAt;
            } elseif ($nextChargeAt && $nextChargeAt > $now) {
                $isActiveSubscription = true;
                $activeUntil = $nextChargeAt;
            }
        }

        if ($subscription->getStatus() === Subscription::STATUS_TRIAL) {
            $trialEndsAt = $subscription->getTrialEndsAt();
            if ($trialEndsAt && $trialEndsAt > $now) {
                $isActiveSubscription = true;
                $activeUntil = $trialEndsAt;
            }
        }

        $payerEmail = $this->getUser()?->getUserIdentifier();
        if (!$payerEmail) {
            $this->addFlash('danger', 'No se pudo determinar el email del pagador.');

            return $this->redirectToRoute('app_billing_subscription_show');
        }

        try {
            $payload = [
                'reason' => $billingPlan->getName(),
                'payer_email' => $payerEmail,
                'payer' => [
                    'email' => $payerEmail,
                ],
                'external_reference' => $externalReference,
                'back_url' => $this->generateUrl('app_billing_return', [], UrlGeneratorInterface::ABSOLUTE_URL),
            ];

            $autoRecurring = [
                'frequency' => $billingPlan->getFrequency(),
                'frequency_type' => $billingPlan->getFrequencyType(),
                'transaction_amount' => (float) $billingPlan->getPrice(),
                'currency_id' => $billingPlan->getCurrency(),
            ];

            $response = null;
            $mpPlanId = $billingPlan->getMpPreapprovalPlanId();
            try {
                if ($mpPlanId) {
                    $payload['preapproval_plan_id'] = $mpPlanId;
                } else {
                    $payload['auto_recurring'] = $autoRecurring;
                }

                $response = $mercadoPagoClient->createPreapproval($payload);
            } catch (MercadoPagoApiException $exception) {
                if ($mpPlanId && $this->shouldFallbackToAutoRecurring($exception)) {
                    unset($payload['preapproval_plan_id']);
                    $payload['auto_recurring'] = $autoRecurring;
                    $response = $mercadoPagoClient->createPreapproval($payload);
                } else {
                    throw $exception;
                }
            }
        } catch (MercadoPagoApiException $exception) {
            $this->addFlash('danger', sprintf('Error al iniciar la suscripción en Mercado Pago: %s', $exception->getMessage()));

            return $this->redirectToRoute('app_billing_subscription_show');
        }

        if (!isset($response['id'])) {
            $this->addFlash('danger', 'Mercado Pago no devolvió un identificador de preapproval.');

            return $this->redirectToRoute('app_billing_subscription_show');
        }

        $initPoint = $mercadoPagoMode === 'sandbox'
            ? ($response['sandbox_init_point'] ?? $response['init_point'] ?? null)
            : ($response['init_point'] ?? null);

        if (!$initPoint) {
            $this->addFlash('danger', 'Mercado Pago no devolvió un link de pago.');

            return $this->redirectToRoute('app_billing_subscription_show');
        }

        $mpPreapprovalId = (string) $response['id'];
        if ($subscription->getBusiness()) {
            $link = $subscriptionLinkRepository->findOneBy(['mpPreapprovalId' => $mpPreapprovalId]);
            if (!$link instanceof MercadoPagoSubscriptionLink) {
                $link = new MercadoPagoSubscriptionLink($mpPreapprovalId, 'PENDING');
                $link->setBusiness($subscription->getBusiness());
                $entityManager->persist($link);
            } else {
                $link->setStatus('PENDING');
                if ($link->getBusiness() === null) {
                    $link->setBusiness($subscription->getBusiness());
                }
            }
            $link->setIsPrimary(false);
        }

        if ($isActiveSubscription) {
            $pendingChangeRepository = $entityManager->getRepository(PendingSubscriptionChange::class);
            $pendingChange = $pendingChangeRepository->createQueryBuilder('pendingChange')
                ->andWhere('pendingChange.business = :business')
                ->andWhere('pendingChange.status IN (:statuses)')
                ->setParameter('business', $subscription->getBusiness())
                ->setParameter('statuses', [
                    PendingSubscriptionChange::STATUS_CREATED,
                    PendingSubscriptionChange::STATUS_CHECKOUT_STARTED,
                    PendingSubscriptionChange::STATUS_PAID,
                ])
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();

            if (!$pendingChange) {
                $pendingChange = new PendingSubscriptionChange();
                $pendingChange->setBusiness($subscription->getBusiness());
                $entityManager->persist($pendingChange);
            }

            $currentPrice = $subscription->getPlan()?->getPriceMonthly();
            $targetPrice = $billingPlan->getPrice();
            $type = PendingSubscriptionChange::TYPE_RENEWAL;
            if ($currentPrice !== null && $targetPrice !== null) {
                $currentAmount = (float) $currentPrice;
                $targetAmount = (float) $targetPrice;
                if ($targetAmount > $currentAmount) {
                    $type = PendingSubscriptionChange::TYPE_UPGRADE;
                } elseif ($targetAmount < $currentAmount) {
                    $type = PendingSubscriptionChange::TYPE_DOWNGRADE;
                }
            }

            $effectiveAt = $subscription->getEndAt()
                ?? $subscription->getNextPaymentAt()
                ?? $subscription->getTrialEndsAt()
                ?? $now;

            $pendingChange
                ->setCurrentSubscription($subscription)
                ->setTargetBillingPlan($billingPlan)
                ->setType($type)
                ->setStatus(PendingSubscriptionChange::STATUS_CHECKOUT_STARTED)
                ->setEffectiveAt($effectiveAt)
                ->setMpPreapprovalId($mpPreapprovalId)
                ->setExternalReference($externalReference)
                ->setInitPoint($initPoint);

            $currentEndsAt = $activeUntil;
            $subscriptionNotificationService->onSubscriptionChangeScheduled(
                $subscription,
                $billingPlan->getName(),
                $pendingChange->getEffectiveAt(),
                $currentEndsAt,
            );
            if ($subscription->getBusiness()) {
                $platformNotificationService->notifySubscriptionChangeScheduled(
                    $subscription->getBusiness(),
                    $subscription,
                    $billingPlan->getName(),
                    $pendingChange->getEffectiveAt(),
                    $currentEndsAt,
                );
            }

            $this->addFlash(
                'success',
                sprintf(
                    'Cambio de plan programado. Tu plan actual sigue activo hasta %s.',
                    $activeUntil ? $activeUntil->format('d/m') : $now->format('d/m')
                )
            );
        } else {
            $pendingChange = new PendingSubscriptionChange();
            $pendingChange
                ->setBusiness($subscription->getBusiness())
                ->setCurrentSubscription($subscription)
                ->setTargetBillingPlan($billingPlan)
                ->setType(PendingSubscriptionChange::TYPE_RENEWAL)
                ->setStatus(PendingSubscriptionChange::STATUS_CHECKOUT_STARTED)
                ->setEffectiveAt($now)
                ->setMpPreapprovalId($mpPreapprovalId)
                ->setExternalReference($externalReference)
                ->setInitPoint($initPoint);
            $entityManager->persist($pendingChange);

            $subscription
                ->setMpPreapprovalId($mpPreapprovalId)
                ->setExternalReference($externalReference)
                ->setPayerEmail($payerEmail)
                ->setLastSyncedAt(new \DateTimeImmutable())
                ->setNextPaymentAt($this->parseMpDate($response['next_payment_date'] ?? null));
        }

        $entityManager->flush();

        return new RedirectResponse($initPoint);
    }

    #[Route('/app/billing/subscription/pause', name: 'app_billing_pause', methods: ['POST'])]
    public function pause(
        Request $request,
        SubscriptionContext $subscriptionContext,
        MercadoPagoClient $mercadoPagoClient,
        EntityManagerInterface $entityManager,
        MercadoPagoSubscriptionLinkRepository $subscriptionLinkRepository,
        MPSubscriptionManager $subscriptionManager,
    ): RedirectResponse {
        return $this->handleStatusChange(
            $request,
            $subscriptionContext,
            $mercadoPagoClient,
            $entityManager,
            $subscriptionLinkRepository,
            $subscriptionManager,
            'paused',
            'Suscripción pausada.'
        );
    }

    #[Route('/app/billing/subscription/reactivate', name: 'app_billing_reactivate', methods: ['POST'])]
    public function reactivate(
        Request $request,
        SubscriptionContext $subscriptionContext,
        MercadoPagoClient $mercadoPagoClient,
        EntityManagerInterface $entityManager,
        MercadoPagoSubscriptionLinkRepository $subscriptionLinkRepository,
        MPSubscriptionManager $subscriptionManager,
    ): RedirectResponse {
        return $this->handleStatusChange(
            $request,
            $subscriptionContext,
            $mercadoPagoClient,
            $entityManager,
            $subscriptionLinkRepository,
            $subscriptionManager,
            'authorized',
            'Suscripción reactivada.'
        );
    }

    #[Route('/app/billing/subscription/cancel', name: 'app_billing_cancel', methods: ['POST'])]
    public function cancel(
        Request $request,
        SubscriptionContext $subscriptionContext,
        MercadoPagoClient $mercadoPagoClient,
        EntityManagerInterface $entityManager,
        MercadoPagoSubscriptionLinkRepository $subscriptionLinkRepository,
        MPSubscriptionManager $subscriptionManager,
    ): RedirectResponse {
        return $this->handleStatusChange(
            $request,
            $subscriptionContext,
            $mercadoPagoClient,
            $entityManager,
            $subscriptionLinkRepository,
            $subscriptionManager,
            'cancelled',
            'Suscripción cancelada.'
        );
    }

    #[Route('/app/billing/subscription/pending/cancel', name: 'app_billing_subscription_cancel_pending', methods: ['POST'])]
    public function cancelPendingChange(
        Request $request,
        SubscriptionContext $subscriptionContext,
        MercadoPagoClient $mercadoPagoClient,
        EntityManagerInterface $entityManager,
    ): RedirectResponse {
        if (!$this->isCsrfTokenValid('cancel_pending_change', (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('app_billing_subscription_show');
        }

        $subscription = $subscriptionContext->getCurrentSubscription($this->getUser());
        if (!$subscription || !$subscription->getBusiness()) {
            $this->addFlash('danger', 'No se encontró una suscripción asociada al comercio.');

            return $this->redirectToRoute('app_billing_subscription_show');
        }

        $pendingChange = $entityManager->getRepository(PendingSubscriptionChange::class)
            ->createQueryBuilder('pendingChange')
            ->andWhere('pendingChange.business = :business')
            ->andWhere('pendingChange.status IN (:statuses)')
            ->setParameter('business', $subscription->getBusiness())
            ->setParameter('statuses', [
                PendingSubscriptionChange::STATUS_CREATED,
                PendingSubscriptionChange::STATUS_CHECKOUT_STARTED,
                PendingSubscriptionChange::STATUS_PAID,
            ])
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$pendingChange instanceof PendingSubscriptionChange) {
            $this->addFlash('warning', 'No hay cambios de plan pendientes para cancelar.');

            return $this->redirectToRoute('app_billing_subscription_show');
        }

        $mpPreapprovalId = $pendingChange->getMpPreapprovalId();
        if ($mpPreapprovalId) {
            try {
                $mercadoPagoClient->cancelPreapproval($mpPreapprovalId);
            } catch (MercadoPagoApiException $exception) {
                $this->addFlash('danger', sprintf('No se pudo cancelar el preapproval en Mercado Pago: %s', $exception->getMessage()));

                return $this->redirectToRoute('app_billing_subscription_show');
            }
        }

        $pendingChange->setStatus(PendingSubscriptionChange::STATUS_CANCELED);
        $entityManager->flush();

        $this->addFlash('success', 'El cambio de plan fue cancelado.');

        return $this->redirectToRoute('app_billing_subscription_show');
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
        MercadoPagoSubscriptionLinkRepository $subscriptionLinkRepository,
        MPSubscriptionManager $subscriptionManager,
        string $targetStatus,
        string $successMessage,
    ): RedirectResponse {
        if (!$this->isCsrfTokenValid('billing_subscription_action', (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('app_billing_subscription_show');
        }

        $subscription = $subscriptionContext->getCurrentSubscription($this->getUser());
        if (!$subscription) {
            $this->addFlash('warning', 'No hay una suscripción activa para gestionar.');

            return $this->redirectToRoute('app_billing_subscription_show');
        }

        $mpPreapprovalId = $subscription->getMpPreapprovalId();
        if (!$mpPreapprovalId && $subscription->getBusiness()) {
            $primaryLink = $subscriptionLinkRepository->findOneBy([
                'business' => $subscription->getBusiness(),
                'isPrimary' => true,
            ]);
            $mpPreapprovalId = $primaryLink?->getMpPreapprovalId();
            if ($mpPreapprovalId) {
                $subscription->setMpPreapprovalId($mpPreapprovalId);
            }
        }

        if (!$mpPreapprovalId) {
            $this->addFlash('warning', 'No hay una suscripción activa para gestionar.');

            return $this->redirectToRoute('app_billing_subscription_show');
        }

        try {
            if ($targetStatus === 'cancelled') {
                $mercadoPagoClient->cancelPreapproval($mpPreapprovalId);
            } else {
                $mercadoPagoClient->updatePreapproval($mpPreapprovalId, ['status' => $targetStatus]);
            }

            $preapproval = $mercadoPagoClient->getPreapproval($mpPreapprovalId);
            $this->applyPreapprovalToSubscription($subscription, $preapproval);
            $business = $subscription->getBusiness();
            if ($targetStatus === 'cancelled' && $business) {
                $link = $subscriptionLinkRepository->findOneBy([
                    'business' => $business,
                    'mpPreapprovalId' => $mpPreapprovalId,
                ]);
                if ($link) {
                    $link->setStatus('CANCELLED');
                    $link->setIsPrimary(false);
                }
            }
            $entityManager->flush();
            if ($targetStatus === 'cancelled' && $business) {
                $subscriptionManager->cancelAllActiveAfterMutation($business);
            }
            $this->addFlash('success', $successMessage);
        } catch (MercadoPagoApiException $exception) {
            if (
                $exception->getStatusCode() === 400
                && str_contains($exception->getResponseBody(), 'You can not modify a cancelled preapproval.')
            ) {
                $preapproval = $mercadoPagoClient->getPreapproval($mpPreapprovalId);
                $this->applyPreapprovalToSubscription($subscription, $preapproval);
                $business = $subscription->getBusiness();
                if ($targetStatus === 'cancelled' && $business) {
                    $link = $subscriptionLinkRepository->findOneBy([
                        'business' => $business,
                        'mpPreapprovalId' => $mpPreapprovalId,
                    ]);
                    if ($link) {
                        $link->setStatus('CANCELLED');
                        $link->setIsPrimary(false);
                    }
                }
                $entityManager->flush();
                if ($targetStatus === 'cancelled' && $business) {
                    $subscriptionManager->cancelAllActiveAfterMutation($business);
                }
                $this->addFlash('warning', 'La suscripción ya estaba cancelada en Mercado Pago.');

                return $this->redirectToRoute('app_billing_subscription_show');
            }

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
            ->setLastSyncedAt(new \DateTimeImmutable())
            ->setNextPaymentAt($this->parseMpDate($preapproval['next_payment_date'] ?? null));
    }

    private function parseMpDate(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function shouldFallbackToAutoRecurring(MercadoPagoApiException $exception): bool
    {
        if ($exception->getStatusCode() === 404) {
            return true;
        }

        $body = $exception->getResponseBody();
        if (stripos($body, 'template') !== false) {
            return true;
        }

        return stripos($body, 'card_token_id') !== false;
    }
}
