<?php

namespace App\Controller;

use App\Entity\BillingWebhookEvent;
use App\Entity\Subscription;
use App\Exception\MercadoPagoApiException;
use App\Repository\BillingWebhookEventRepository;
use App\Repository\SubscriptionRepository;
use App\Service\MercadoPagoClient;
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
        MercadoPagoClient $mercadoPagoClient,
        EntityManagerInterface $entityManager,
        SubscriptionNotificationService $subscriptionNotificationService,
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
        $resourceType = $payload['type'] ?? $payload['topic'] ?? null;
        if (!$resourceType && is_string($resource)) {
            $resourceType = str_contains($resource, '/preapproval') ? 'preapproval' : null;
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

        if ($resourceType !== null && !in_array($resourceType, ['preapproval', 'subscription', 'payment'], true)) {
            $event->setProcessedAt(new \DateTimeImmutable());
            $entityManager->flush();

            return new Response('ignored_resource', Response::HTTP_OK);
        }

        try {
            $paymentStatus = null;
            if ($resourceType === 'payment') {
                $payment = $mercadoPagoClient->getPayment($resourceId);
                $this->storePaymentDetails($event, $payload, $payment);
                $paymentStatus = is_string($payment['status'] ?? null) ? $payment['status'] : null;
                $preapprovalId = $this->extractPreapprovalIdFromPayment($payment);
                $subscription = null;
                if (!$preapprovalId) {
                    $externalReference = $payment['external_reference'] ?? null;
                    if (is_string($externalReference) && ctype_digit($externalReference)) {
                        $subscription = $subscriptionRepository->find((int) $externalReference);
                        if ($subscription?->getMpPreapprovalId()) {
                            $preapprovalId = $subscription->getMpPreapprovalId();
                        }
                    }
                }

                if (!$preapprovalId && $subscription) {
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

            $preapproval = $mercadoPagoClient->getPreapproval($resourceId);
        } catch (MercadoPagoApiException $exception) {
            $logger->error('Mercado Pago webhook processing failed', [
                'event_id' => $eventId,
                'resource_id' => $resourceId,
                'message' => $exception->getMessage(),
            ]);

            return new Response('mp_error', Response::HTTP_BAD_GATEWAY);
        }

        $subscription = $subscriptionRepository->findOneBy(['mpPreapprovalId' => $resourceId]);
        if ($subscription instanceof Subscription) {
            $previousStatus = $subscription->getStatus();
            $subscription->setStatus($this->mapStatus($preapproval['status'] ?? null));
            $subscription->setMpPreapprovalPlanId($preapproval['preapproval_plan_id'] ?? $subscription->getMpPreapprovalPlanId());
            $subscription->setPayerEmail($preapproval['payer_email'] ?? $subscription->getPayerEmail());
            $subscription->setLastSyncedAt(new \DateTimeImmutable());
            $subscription->setNextPaymentAt($this->parseMpDate($preapproval['next_payment_date'] ?? null));

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
}
