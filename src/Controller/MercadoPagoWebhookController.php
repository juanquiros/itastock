<?php

namespace App\Controller;

use App\Entity\BillingWebhookEvent;
use App\Entity\Business;
use App\Entity\PendingSubscriptionChange;
use App\Entity\Subscription;
use App\Exception\MercadoPagoApiException;
use App\Repository\BillingWebhookEventRepository;
use App\Repository\MercadoPagoSubscriptionLinkRepository;
use App\Repository\SubscriptionRepository;
use App\Service\MercadoPagoClient;
use App\Service\MPSubscriptionManager;
use App\Service\PlatformNotificationService;
use App\Service\SubscriptionNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class MercadoPagoWebhookController extends AbstractController
{
    #[Route('/webhooks/mercadopago', name: 'public_mercadopago_webhook', methods: ['POST'])]
    public function __invoke(
        Request $request,
        BillingWebhookEventRepository $eventRepository,
        SubscriptionRepository $subscriptionRepository,
        MercadoPagoSubscriptionLinkRepository $subscriptionLinkRepository,
        MercadoPagoClient $mercadoPagoClient,
        EntityManagerInterface $entityManager,
        SubscriptionNotificationService $subscriptionNotificationService,
        PlatformNotificationService $platformNotificationService,
        MPSubscriptionManager $subscriptionManager,
        LoggerInterface $logger,
    ): Response {
        $payloadRaw = $request->getContent();
        $payload = json_decode($payloadRaw, true);
        if (!is_array($payload)) {
            $payload = [];
        }

        $eventId = $payload['id'] ?? null;
        $resourceId = $payload['data']['id'] ?? $payload['resource_id'] ?? null;
        $resource = $payload['resource'] ?? null;
        if ($resourceId === null && is_string($resource)) {
            $resourceId = trim((string) basename($resource)) ?: null;
        }
        $rawType = $payload['type'] ?? $payload['topic'] ?? null;
        $resourceType = $rawType;
        if (!$resourceType && is_string($resource)) {
            $resourceType = str_contains($resource, '/preapproval') ? 'preapproval' : null;
        }
        if (is_string($resourceType)) {
            $lowerType = strtolower($resourceType);
            if (str_contains($lowerType, 'preapproval')) {
                $resourceType = 'preapproval';
            } elseif (str_contains($lowerType, 'subscription_authorized_payment')) {
                $resourceType = 'authorized_payment';
            } elseif (str_contains($lowerType, 'payment')) {
                $resourceType = 'payment';
            }
        }

        if ($eventRepository->findProcessedByEventOrResource($eventId, $resourceId)) {
            return new Response('already_processed', Response::HTTP_OK);
        }

        $headers = [
            'user_agent' => $request->headers->get('user-agent'),
            'x_signature' => $request->headers->get('x-signature'),
            'x_request_id' => $request->headers->get('x-request-id'),
            'content_type' => $request->headers->get('content-type'),
        ];

        $event = new BillingWebhookEvent(
            $payloadRaw !== '' ? $payloadRaw : json_encode($payload, JSON_UNESCAPED_UNICODE),
            json_encode($headers, JSON_UNESCAPED_UNICODE)
        );
        $event->setEventId($eventId)->setResourceId($resourceId);
        $entityManager->persist($event);
        $entityManager->flush();

        if ($resourceId === null) {
            $event->setProcessedAt(new \DateTimeImmutable());
            $entityManager->flush();

            return new Response('missing_resource', Response::HTTP_OK);
        }

        if ($resourceType !== null && !in_array($resourceType, ['preapproval', 'subscription', 'payment', 'authorized_payment'], true)) {
            $event->setProcessedAt(new \DateTimeImmutable());
            $entityManager->flush();

            return new Response('ignored_resource', Response::HTTP_OK);
        }

        $confirmedPreapprovalId = null;
        try {
            $paymentStatus = null;
            $subscription = null;
            if ($resourceType === 'payment') {
                $payment = $mercadoPagoClient->getPayment($resourceId);
                $this->storePaymentDetails($event, $payload, $payment);
                $paymentStatus = is_string($payment['status'] ?? null) ? $payment['status'] : null;
                $preapprovalId = $this->extractPreapprovalIdFromPayment($payment);
                if (!$preapprovalId) {
                    $externalReference = $payment['external_reference'] ?? null;
                    if (is_string($externalReference)) {
                        $subscription = $this->resolveSubscriptionFromExternalReference(
                            $externalReference,
                            $subscriptionRepository,
                            $entityManager,
                        );
                        if ($subscription?->getMpPreapprovalId()) {
                            $preapprovalId = $subscription->getMpPreapprovalId();
                        }
                        if ($subscription && $paymentStatus === 'approved') {
                            $pendingChange = $entityManager->getRepository(PendingSubscriptionChange::class)
                                ->createQueryBuilder('pendingChange')
                                ->andWhere('pendingChange.currentSubscription = :subscription')
                                ->andWhere('pendingChange.status IN (:statuses)')
                                ->setParameter('subscription', $subscription)
                                ->setParameter('statuses', [
                                    PendingSubscriptionChange::STATUS_CREATED,
                                    PendingSubscriptionChange::STATUS_CHECKOUT_STARTED,
                                ])
                                ->setMaxResults(1)
                                ->getQuery()
                                ->getOneOrNullResult();

                            if ($pendingChange instanceof PendingSubscriptionChange) {
                                $pendingChange
                                    ->setStatus(PendingSubscriptionChange::STATUS_PAID)
                                    ->setPaidAt(new \DateTimeImmutable());
                                $logger->info('Pending subscription change marked as paid from payment event.', [
                                    'pending_change_id' => $pendingChange->getId(),
                                    'subscription_id' => $subscription->getId(),
                                ]);
                                $billingPlan = $pendingChange->getTargetBillingPlan();
                                $subscriptionNotificationService->onSubscriptionChangePaid(
                                    $subscription,
                                    $billingPlan?->getName(),
                                    $pendingChange->getEffectiveAt()
                                );
                                if ($subscription->getBusiness()) {
                                    $platformNotificationService->notifySubscriptionChangePaid(
                                        $subscription->getBusiness(),
                                        $subscription,
                                        $billingPlan?->getName(),
                                        $pendingChange->getEffectiveAt(),
                                        $pendingChange->getPaidAt()
                                    );
                                }
                            }
                        }
                    }
                }

                if (!$preapprovalId && $subscription) {
                    $previousStatus = $subscription->getStatus();
                    if ($paymentStatus === 'approved') {
                        $subscription->setStatus(Subscription::STATUS_ACTIVE);
                    }
                    if ($paymentStatus === 'rejected' || $paymentStatus === 'cancelled') {
                        $subscription->setStatus(Subscription::STATUS_PAST_DUE);
                    }
                    if (is_string($payment['payer_email'] ?? null)) {
                        $subscription->setPayerEmail($payment['payer_email']);
                    }
                    $subscription->setLastSyncedAt(new \DateTimeImmutable());
                    $event->setProcessedAt(new \DateTimeImmutable());
                    $entityManager->flush();

                    if ($previousStatus !== $subscription->getStatus() && $subscription->getStatus() === Subscription::STATUS_ACTIVE) {
                        $subscriptionNotificationService->onSubscriptionActivated($subscription);
                    }
                    if ($paymentStatus === 'approved') {
                        $subscriptionNotificationService->onPaymentReceived($subscription);
                    }
                    if ($paymentStatus === 'rejected' || $paymentStatus === 'cancelled') {
                        $subscriptionNotificationService->onPaymentFailed($subscription);
                    }

                    return new Response('payment_synced', Response::HTTP_OK);
                }

                if (!$preapprovalId) {
                    $event->setProcessedAt(new \DateTimeImmutable());
                    $entityManager->flush();

                    return new Response('missing_preapproval', Response::HTTP_OK);
                }

                $resourceId = (string) $preapprovalId;
            }
            if ($resourceType === 'authorized_payment') {
                $authorizedPayment = $mercadoPagoClient->getAuthorizedPayment($resourceId);
                $this->storeAuthorizedPaymentDetails($event, $payload, $authorizedPayment);
                $paymentStatus = is_string($authorizedPayment['status'] ?? null) ? $authorizedPayment['status'] : null;
                $preapprovalId = $this->extractPreapprovalIdFromAuthorizedPayment($authorizedPayment);
                if (!$preapprovalId) {
                    $externalReference = $authorizedPayment['external_reference'] ?? null;
                    if (is_string($externalReference)) {
                        $subscription = $this->resolveSubscriptionFromExternalReference(
                            $externalReference,
                            $subscriptionRepository,
                            $entityManager,
                        );
                        if ($subscription?->getMpPreapprovalId()) {
                            $preapprovalId = $subscription->getMpPreapprovalId();
                        }
                        if ($subscription && $paymentStatus === 'approved') {
                            $pendingChange = $entityManager->getRepository(PendingSubscriptionChange::class)
                                ->createQueryBuilder('pendingChange')
                                ->andWhere('pendingChange.currentSubscription = :subscription')
                                ->andWhere('pendingChange.status IN (:statuses)')
                                ->setParameter('subscription', $subscription)
                                ->setParameter('statuses', [
                                    PendingSubscriptionChange::STATUS_CREATED,
                                    PendingSubscriptionChange::STATUS_CHECKOUT_STARTED,
                                ])
                                ->setMaxResults(1)
                                ->getQuery()
                                ->getOneOrNullResult();

                            if ($pendingChange instanceof PendingSubscriptionChange) {
                                $pendingChange
                                    ->setStatus(PendingSubscriptionChange::STATUS_PAID)
                                    ->setPaidAt(new \DateTimeImmutable());
                                $logger->info('Pending subscription change marked as paid from authorized payment event.', [
                                    'pending_change_id' => $pendingChange->getId(),
                                    'subscription_id' => $subscription->getId(),
                                ]);
                                $billingPlan = $pendingChange->getTargetBillingPlan();
                                $subscriptionNotificationService->onSubscriptionChangePaid(
                                    $subscription,
                                    $billingPlan?->getName(),
                                    $pendingChange->getEffectiveAt()
                                );
                                if ($subscription->getBusiness()) {
                                    $platformNotificationService->notifySubscriptionChangePaid(
                                        $subscription->getBusiness(),
                                        $subscription,
                                        $billingPlan?->getName(),
                                        $pendingChange->getEffectiveAt(),
                                        $pendingChange->getPaidAt()
                                    );
                                }
                            }
                        }
                    }
                }

                if (!$preapprovalId && $subscription) {
                    $previousStatus = $subscription->getStatus();
                    if ($paymentStatus === 'approved') {
                        $subscription->setStatus(Subscription::STATUS_ACTIVE);
                    }
                    if ($paymentStatus === 'rejected' || $paymentStatus === 'cancelled') {
                        $subscription->setStatus(Subscription::STATUS_PAST_DUE);
                    }
                    $payerEmail = $authorizedPayment['payer_email'] ?? null;
                    if (!is_string($payerEmail) && is_array($authorizedPayment['payer'] ?? null)) {
                        $payerEmail = $authorizedPayment['payer']['email'] ?? null;
                    }
                    if (is_string($payerEmail)) {
                        $subscription->setPayerEmail($payerEmail);
                    }
                    $subscription->setLastSyncedAt(new \DateTimeImmutable());
                    $event->setProcessedAt(new \DateTimeImmutable());
                    $entityManager->flush();

                    if ($previousStatus !== $subscription->getStatus() && $subscription->getStatus() === Subscription::STATUS_ACTIVE) {
                        $subscriptionNotificationService->onSubscriptionActivated($subscription);
                    }
                    if ($paymentStatus === 'approved') {
                        $subscriptionNotificationService->onPaymentReceived($subscription);
                    }
                    if ($paymentStatus === 'rejected' || $paymentStatus === 'cancelled') {
                        $subscriptionNotificationService->onPaymentFailed($subscription);
                    }

                    return new Response('authorized_payment_synced', Response::HTTP_OK);
                }

                if (!$preapprovalId) {
                    $event->setProcessedAt(new \DateTimeImmutable());
                    $entityManager->flush();

                    return new Response('missing_preapproval', Response::HTTP_OK);
                }

                $resourceId = (string) $preapprovalId;

                $link = $subscriptionLinkRepository->findOneBy(['mpPreapprovalId' => $preapprovalId]);
                $business = $link?->getBusiness() ?? $subscription?->getBusiness();
                if ($business instanceof Business) {
                    $subscriptionManager->confirmNewSubscriptionActive($business, $preapprovalId);
                    $confirmedPreapprovalId = $preapprovalId;
                    $logger->info('Authorized payment confirmed new MP subscription.', [
                        'business_id' => $business->getId(),
                        'mp_preapproval_id' => $preapprovalId,
                    ]);
                }
            }

            $preapproval = $mercadoPagoClient->getPreapproval($resourceId);
        } catch (MercadoPagoApiException $exception) {
            $logger->error('Mercado Pago webhook processing failed', [
                'event_id' => $eventId,
                'resource_id' => $resourceId,
                'message' => $exception->getMessage(),
            ]);

            return new Response('mp_error', Response::HTTP_BAD_GATEWAY);
        }

        $preapprovalId = (string) ($preapproval['id'] ?? $resourceId);
        $subscription = $subscriptionRepository->findOneBy(['mpPreapprovalId' => $preapprovalId]);
        if ($subscription instanceof Subscription) {
            $previousStatus = $subscription->getStatus();
            $subscription->setStatus($this->mapStatus($preapproval['status'] ?? null));
            $subscription->setMpPreapprovalPlanId($preapproval['preapproval_plan_id'] ?? $subscription->getMpPreapprovalPlanId());
            $subscription->setPayerEmail($preapproval['payer_email'] ?? $subscription->getPayerEmail());
            $subscription->setLastSyncedAt(new \DateTimeImmutable());
            $subscription->setNextPaymentAt($this->parseMpDate($preapproval['next_payment_date'] ?? null));

            if ($paymentStatus === 'approved') {
                $subscription->setStatus(Subscription::STATUS_ACTIVE);
            }

            if ($previousStatus !== $subscription->getStatus() && $subscription->getStatus() === Subscription::STATUS_ACTIVE) {
                $subscriptionNotificationService->onSubscriptionActivated($subscription);
            }
            if ($subscription->getStatus() === Subscription::STATUS_CANCELED) {
                $subscriptionNotificationService->onCanceled($subscription);
            }
            if ($paymentStatus === 'approved') {
                $subscriptionNotificationService->onPaymentReceived($subscription, $subscription->getNextPaymentAt());
            }
            if ($paymentStatus === 'rejected' || $paymentStatus === 'cancelled' || $subscription->getStatus() === Subscription::STATUS_PAST_DUE) {
                $subscriptionNotificationService->onPaymentFailed($subscription);
            }
        }

        $preapprovalStatus = strtolower((string) ($preapproval['status'] ?? ''));
        if (in_array($preapprovalStatus, ['active', 'authorized', 'approved'], true)) {
            $business = $this->resolveBusinessForPreapproval(
                $preapproval,
                $subscription,
                $subscriptionLinkRepository,
                $entityManager
            );
            if (
                $business instanceof Business
                && ($confirmedPreapprovalId === null || $confirmedPreapprovalId !== $preapprovalId)
            ) {
                $subscriptionManager->confirmNewSubscriptionActive($business, $preapprovalId);
            }
        }

        $pendingChange = $entityManager->getRepository(PendingSubscriptionChange::class)
            ->findOneBy(['mpPreapprovalId' => $preapprovalId]);
        if ($pendingChange instanceof PendingSubscriptionChange) {
            $paymentConfirmed = $paymentStatus === 'approved';
            $preapprovalStatus = $preapproval['status'] ?? null;
            if (is_string($preapprovalStatus) && in_array($preapprovalStatus, ['authorized', 'active', 'approved'], true)) {
                $paymentConfirmed = true;
            }

            if (
                $paymentConfirmed
                && !in_array($pendingChange->getStatus(), [
                    PendingSubscriptionChange::STATUS_PAID,
                    PendingSubscriptionChange::STATUS_APPLIED,
                ], true)
            ) {
                $pendingChange
                    ->setStatus(PendingSubscriptionChange::STATUS_PAID)
                    ->setPaidAt(new \DateTimeImmutable());
                $logger->info('Pending subscription change marked as paid.', [
                    'pending_change_id' => $pendingChange->getId(),
                    'mp_preapproval_id' => $resourceId,
                ]);
                $subscription = $pendingChange->getCurrentSubscription();
                $billingPlan = $pendingChange->getTargetBillingPlan();
                if ($subscription instanceof Subscription) {
                    $subscriptionNotificationService->onSubscriptionChangePaid(
                        $subscription,
                        $billingPlan?->getName(),
                        $pendingChange->getEffectiveAt()
                    );
                    if ($subscription->getBusiness()) {
                        $platformNotificationService->notifySubscriptionChangePaid(
                            $subscription->getBusiness(),
                            $subscription,
                            $billingPlan?->getName(),
                            $pendingChange->getEffectiveAt(),
                            $pendingChange->getPaidAt()
                        );
                    }
                }
            }
        }

        $event->setProcessedAt(new \DateTimeImmutable());
        $entityManager->flush();

        return new Response('ok', Response::HTTP_OK);
    }

    private function extractPreapprovalIdFromPayment(array $payment): ?string
    {
        $candidate = $payment['preapproval_id'] ?? $payment['subscription_id'] ?? null;
        if (is_string($candidate) && $candidate !== '') {
            return $candidate;
        }

        $metadata = $payment['metadata'] ?? null;
        if (is_array($metadata)) {
            $candidate = $metadata['preapproval_id'] ?? $metadata['subscription_id'] ?? null;
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        $additionalInfo = $payment['additional_info'] ?? null;
        if (is_array($additionalInfo)) {
            $candidate = $additionalInfo['preapproval_id'] ?? $additionalInfo['subscription_id'] ?? null;
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    private function extractPreapprovalIdFromAuthorizedPayment(array $authorizedPayment): ?string
    {
        $candidate = $authorizedPayment['preapproval_id'] ?? $authorizedPayment['subscription_id'] ?? null;
        if (is_string($candidate) && $candidate !== '') {
            return $candidate;
        }

        if (is_array($authorizedPayment['preapproval'] ?? null)) {
            $candidate = $authorizedPayment['preapproval']['id'] ?? null;
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        $metadata = $authorizedPayment['metadata'] ?? null;
        if (is_array($metadata)) {
            $candidate = $metadata['preapproval_id'] ?? $metadata['subscription_id'] ?? null;
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    private function resolveSubscriptionFromExternalReference(
        string $externalReference,
        SubscriptionRepository $subscriptionRepository,
        EntityManagerInterface $entityManager,
    ): ?Subscription {
        if (ctype_digit($externalReference)) {
            return $subscriptionRepository->find((int) $externalReference);
        }

        if (!preg_match('/^business:(\\d+)$/', $externalReference, $matches)) {
            return null;
        }

        $businessId = (int) $matches[1];
        if ($businessId <= 0) {
            return null;
        }

        $business = $entityManager->getRepository(Business::class)->find($businessId);
        if (!$business instanceof Business) {
            return null;
        }

        return $business->getSubscription();
    }

    private function resolveBusinessForPreapproval(
        array $preapproval,
        ?Subscription $subscription,
        MercadoPagoSubscriptionLinkRepository $subscriptionLinkRepository,
        EntityManagerInterface $entityManager,
    ): ?Business {
        $externalReference = $preapproval['external_reference'] ?? null;
        if (is_string($externalReference) && preg_match('/^business:(\\d+)$/', $externalReference, $matches)) {
            $businessId = (int) $matches[1];
            if ($businessId > 0) {
                $business = $entityManager->getRepository(Business::class)->find($businessId);
                if ($business instanceof Business) {
                    return $business;
                }
            }
        }

        $preapprovalId = $preapproval['id'] ?? null;
        if (is_string($preapprovalId) && $preapprovalId !== '') {
            $link = $subscriptionLinkRepository->findOneBy(['mpPreapprovalId' => $preapprovalId]);
            if ($link instanceof \App\Entity\MercadoPagoSubscriptionLink) {
                return $link->getBusiness();
            }
        }

        return $subscription?->getBusiness();
    }

    private function mapStatus(?string $status): string
    {
        return match ($status) {
            'authorized', 'active' => Subscription::STATUS_ACTIVE,
            'paused', 'suspended' => Subscription::STATUS_SUSPENDED,
            'past_due' => Subscription::STATUS_PAST_DUE,
            'cancelled', 'canceled' => Subscription::STATUS_CANCELED,
            default => Subscription::STATUS_PENDING,
        };
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

    private function storePaymentDetails(BillingWebhookEvent $event, array $payload, array $payment): void
    {
        $payload['payment'] = [
            'id' => $payment['id'] ?? null,
            'status' => $payment['status'] ?? null,
            'status_detail' => $payment['status_detail'] ?? null,
            'external_reference' => $payment['external_reference'] ?? null,
            'preapproval_id' => $payment['preapproval_id'] ?? $payment['subscription_id'] ?? null,
            'date_created' => $payment['date_created'] ?? null,
        ];

        $event->setPayload(json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    private function storeAuthorizedPaymentDetails(BillingWebhookEvent $event, array $payload, array $authorizedPayment): void
    {
        $payload['authorized_payment'] = [
            'id' => $authorizedPayment['id'] ?? null,
            'status' => $authorizedPayment['status'] ?? null,
            'status_detail' => $authorizedPayment['status_detail'] ?? null,
            'external_reference' => $authorizedPayment['external_reference'] ?? null,
            'preapproval_id' => $authorizedPayment['preapproval_id'] ?? $authorizedPayment['subscription_id'] ?? null,
            'date_created' => $authorizedPayment['date_created'] ?? null,
        ];

        $event->setPayload(json_encode($payload, JSON_UNESCAPED_UNICODE));
    }
}
