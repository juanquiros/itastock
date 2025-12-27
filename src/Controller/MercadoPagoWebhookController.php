<?php

namespace App\Controller;

use App\Entity\BillingWebhookEvent;
use App\Entity\Subscription;
use App\Exception\MercadoPagoApiException;
use App\Repository\BillingWebhookEventRepository;
use App\Repository\SubscriptionRepository;
use App\Service\MercadoPagoClient;
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

        try {
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
            $subscription->setStatus($this->mapStatus($preapproval['status'] ?? null));
            $subscription->setMpPreapprovalPlanId($preapproval['preapproval_plan_id'] ?? $subscription->getMpPreapprovalPlanId());
            $subscription->setPayerEmail($preapproval['payer_email'] ?? $subscription->getPayerEmail());
            $subscription->setLastSyncedAt(new \DateTimeImmutable());
        }

        $event->setProcessedAt(new \DateTimeImmutable());
        $entityManager->flush();

        return new Response('ok', Response::HTTP_OK);
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
}
