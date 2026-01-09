<?php

namespace App\Service;

use App\Entity\BillingPlan;
use App\Entity\Business;
use App\Entity\MercadoPagoSubscriptionLink;
use App\Entity\PendingSubscriptionChange;
use App\Entity\User;
use App\Exception\MercadoPagoApiException;
use App\Repository\MercadoPagoSubscriptionLinkRepository;
use App\Service\Result\ReconcileResult;
use App\Service\Result\StartChangeResult;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class MPSubscriptionManager
{
    public function __construct(
        private readonly MercadoPagoClient $mercadoPagoClient,
        private readonly EntityManagerInterface $entityManager,
        private readonly MercadoPagoSubscriptionLinkRepository $subscriptionLinkRepository,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly PlatformNotificationService $platformNotificationService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function startChangePlan(Business $business, BillingPlan $targetPlan, User $admin): StartChangeResult
    {
        $pendingChange = $this->entityManager->getRepository(PendingSubscriptionChange::class)
            ->createQueryBuilder('pendingChange')
            ->andWhere('pendingChange.business = :business')
            ->andWhere('pendingChange.status IN (:statuses)')
            ->setParameter('business', $business)
            ->setParameter('statuses', [
                PendingSubscriptionChange::STATUS_CREATED,
                PendingSubscriptionChange::STATUS_CHECKOUT_STARTED,
                PendingSubscriptionChange::STATUS_PAID,
            ])
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($pendingChange instanceof PendingSubscriptionChange) {
            throw new \RuntimeException('Ya existe un cambio de plan en curso.');
        }

        $payerEmail = $admin->getUserIdentifier();
        if ($payerEmail === '') {
            throw new \RuntimeException('No se pudo determinar el email del pagador.');
        }

        $businessId = $business->getId();
        if ($businessId === null) {
            throw new \RuntimeException('No se pudo determinar el comercio.');
        }
        $externalReference = sprintf('business:%d', $businessId);

        $payload = [
            'reason' => $targetPlan->getName(),
            'payer_email' => $payerEmail,
            'payer' => [
                'email' => $payerEmail,
            ],
            'external_reference' => $externalReference,
            'back_url' => $this->urlGenerator->generate('app_billing_return', [], UrlGeneratorInterface::ABSOLUTE_URL),
        ];

        if ($targetPlan->getMpPreapprovalPlanId()) {
            $payload['preapproval_plan_id'] = $targetPlan->getMpPreapprovalPlanId();
        } else {
            $payload['auto_recurring'] = [
                'frequency' => $targetPlan->getFrequency(),
                'frequency_type' => $targetPlan->getFrequencyType(),
                'transaction_amount' => (float) $targetPlan->getPrice(),
                'currency_id' => $targetPlan->getCurrency(),
            ];
        }

        $response = $this->mercadoPagoClient->createPreapproval($payload);

        if (!isset($response['id']) || !is_string($response['id']) || $response['id'] === '') {
            throw new \RuntimeException('Mercado Pago no devolvió un identificador de preapproval.');
        }

        $initPoint = $response['init_point'] ?? null;
        if (!is_string($initPoint) || $initPoint === '') {
            throw new \RuntimeException('Mercado Pago no devolvió un link de pago.');
        }

        $link = $this->subscriptionLinkRepository->findOneBy([
            'mpPreapprovalId' => $response['id'],
        ]);

        if (!$link instanceof MercadoPagoSubscriptionLink) {
            $link = new MercadoPagoSubscriptionLink((string) $response['id'], 'PENDING');
            $link->setBusiness($business);
            $this->entityManager->persist($link);
        } else {
            $link->setStatus('PENDING');
        }

        $link->setIsPrimary(false);
        $this->entityManager->flush();

        return new StartChangeResult($initPoint, (string) $response['id'], $externalReference);
    }

    public function confirmNewSubscriptionActive(Business $business, string $mpPreapprovalId): void
    {
        $link = $this->subscriptionLinkRepository->findOneBy([
            'business' => $business,
            'mpPreapprovalId' => $mpPreapprovalId,
        ]);

        if (!$link instanceof MercadoPagoSubscriptionLink) {
            $link = new MercadoPagoSubscriptionLink($mpPreapprovalId, 'ACTIVE');
            $link->setBusiness($business);
            $this->entityManager->persist($link);
        } else {
            $link->setStatus('ACTIVE');
        }

        $this->subscriptionLinkRepository->clearPrimaryForBusiness($business);
        $link->setIsPrimary(true);

        $subscription = $business->getSubscription();
        if ($subscription instanceof \App\Entity\Subscription) {
            if ($subscription->getMpPreapprovalId() !== $mpPreapprovalId) {
                $subscription->setMpPreapprovalId($mpPreapprovalId);
            }
            if (!$subscription->getExternalReference()) {
                $subscription->setExternalReference($this->externalReferenceForBusiness($business));
            }
        }

        $this->entityManager->flush();

        $this->ensureSingleActiveAfterMutation($business, $mpPreapprovalId);
    }

    public function cancelOtherActiveSubscriptions(Business $business, string $keepMpPreapprovalId): void
    {
        $preapprovals = $this->fetchPreapprovalsForBusiness($business);
        if ($preapprovals === []) {
            return;
        }

        $pendingCancellation = false;
        foreach ($preapprovals as $preapproval) {
            $preapprovalId = $preapproval['id'] ?? null;
            if (!is_string($preapprovalId) || $preapprovalId === '' || $preapprovalId === $keepMpPreapprovalId) {
                continue;
            }

            $status = $this->normalizeStatus($preapproval['status'] ?? null);
            if (!$this->isCancelableStatus($status)) {
                continue;
            }

            try {
                $this->mercadoPagoClient->cancelPreapproval($preapprovalId);
            } catch (MercadoPagoApiException $exception) {
                $pendingCancellation = true;
                $this->logger->warning('Failed to cancel MP preapproval; queued for reconcile.', [
                    'business_id' => $business->getId(),
                    'mp_preapproval_id' => $preapprovalId,
                    'message' => $exception->getMessage(),
                ]);
                $this->markCancellationPending($business, $preapprovalId);
            }
        }

        if ($pendingCancellation) {
            $this->entityManager->flush();
        }
    }

    public function ensureSingleActiveAfterMutation(Business $business, ?string $preferredMpPreapprovalId = null): void
    {
        $preapprovals = $this->fetchPreapprovalsForBusiness($business);
        if ($preapprovals === []) {
            return;
        }

        $preapprovalsById = [];
        $activePreapprovals = [];
        $cancelablePreapprovals = [];
        foreach ($preapprovals as $preapproval) {
            $preapprovalId = $preapproval['id'] ?? null;
            if (!is_string($preapprovalId) || $preapprovalId === '') {
                continue;
            }

            $status = $this->normalizeStatus($preapproval['status'] ?? null);
            $preapprovalsById[$preapprovalId] = $preapproval;
            if ($this->isActiveStatus($status)) {
                $activePreapprovals[$preapprovalId] = $preapproval;
            }
            if ($this->isCancelableStatus($status)) {
                $cancelablePreapprovals[$preapprovalId] = $preapproval;
            }
        }

        if ($preapprovalsById === []) {
            return;
        }

        $activeCount = count($activePreapprovals);
        $primaryLink = $this->subscriptionLinkRepository->findOneBy([
            'business' => $business,
            'isPrimary' => true,
        ]);
        $primaryMpPreapprovalId = $primaryLink?->getMpPreapprovalId();

        $keepPreapprovalId = null;
        if ($preferredMpPreapprovalId && isset($preapprovalsById[$preferredMpPreapprovalId])) {
            $keepPreapprovalId = $preferredMpPreapprovalId;
        } elseif ($primaryMpPreapprovalId && isset($preapprovalsById[$primaryMpPreapprovalId])) {
            $keepPreapprovalId = $primaryMpPreapprovalId;
        } elseif ($activePreapprovals !== []) {
            $keepPreapprovalId = $this->selectMostRecentPreapprovalId($activePreapprovals);
        } else {
            $keepPreapprovalId = $this->selectMostRecentPreapprovalId($preapprovalsById);
        }

        if (!$keepPreapprovalId) {
            return;
        }

        $canceledPreapprovals = [];
        foreach ($cancelablePreapprovals as $preapprovalId => $preapproval) {
            if ($preapprovalId === $keepPreapprovalId) {
                continue;
            }
            try {
                $this->mercadoPagoClient->cancelPreapproval($preapprovalId);
                $canceledPreapprovals[] = $preapprovalId;
            } catch (MercadoPagoApiException $exception) {
                $this->markCancellationPending($business, $preapprovalId);
                $this->logger->warning('Failed to cancel duplicate MP preapproval.', [
                    'business_id' => $business->getId(),
                    'mp_preapproval_id' => $preapprovalId,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        $this->subscriptionLinkRepository->clearPrimaryForBusiness($business);
        foreach ($preapprovalsById as $preapprovalId => $preapproval) {
            $normalizedStatus = $this->normalizeStatus($preapproval['status'] ?? null);
            $status = strtoupper((string) ($preapproval['status'] ?? 'ACTIVE'));
            $link = $this->resolveLinkForBusiness($business, $preapprovalId, $status);
            if ($preapprovalId === $keepPreapprovalId) {
                $link->setIsPrimary(true);
                if ($this->isActiveStatus($normalizedStatus)) {
                    $link->setStatus('ACTIVE');
                }
            } else {
                $link->setIsPrimary(false);
                if (in_array($preapprovalId, $canceledPreapprovals, true)) {
                    $link->setStatus('CANCELLED');
                }
            }
        }
        $this->entityManager->flush();

        $this->logger->info('Ensured single active MP preapproval for business.', [
            'business_id' => $business->getId(),
            'keep_preapproval_id' => $keepPreapprovalId,
            'canceled_preapprovals' => $canceledPreapprovals,
        ]);

        if ($activeCount > 1 && $canceledPreapprovals !== []) {
            $this->logger->warning('MP inconsistency detected after mutation.', [
                'business_id' => $business->getId(),
                'active_count' => $activeCount,
                'canceled_count' => count($canceledPreapprovals),
            ]);
            $this->platformNotificationService->notifyMpInconsistencyIfRepeated(
                $business,
                $activeCount,
                count($canceledPreapprovals)
            );
        }
    }

    public function reconcileBusinessSubscriptions(Business $business): ReconcileResult
    {
        $preapprovals = $this->fetchPreapprovalsForBusiness($business);
        if ($preapprovals === []) {
            return new ReconcileResult();
        }

        $updatedLinks = [];
        $activePreapprovals = [];
        $pendingPreapprovals = [];
        $primaryLink = $this->subscriptionLinkRepository->findOneBy([
            'business' => $business,
            'isPrimary' => true,
        ]);
        $primaryMpPreapprovalId = $primaryLink?->getMpPreapprovalId();
        $stalePendingCanceled = 0;

        foreach ($preapprovals as $preapproval) {
            $preapprovalId = $preapproval['id'] ?? null;
            if (!is_string($preapprovalId) || $preapprovalId === '') {
                continue;
            }

            $status = strtoupper((string) ($preapproval['status'] ?? 'PENDING'));
            $link = $this->subscriptionLinkRepository->findOneBy([
                'business' => $business,
                'mpPreapprovalId' => $preapprovalId,
            ]);

            $link = $this->resolveLinkForBusiness($business, $preapprovalId, $status);

            $link->setIsPrimary(false);
            $updatedLinks[] = $preapprovalId;

            $normalizedStatus = strtolower((string) ($preapproval['status'] ?? ''));
            if ($this->isActiveStatus($normalizedStatus)) {
                $activePreapprovals[$preapprovalId] = $preapproval;
            }
            if ($this->isPendingStatus($normalizedStatus)) {
                $pendingPreapprovals[$preapprovalId] = $preapproval;
            }
        }

        $activeBefore = count($activePreapprovals);

        if (
            $activeBefore > 0
            && $pendingPreapprovals !== []
            && !$this->hasActivePendingChange($business)
        ) {
            $oldestPendingId = $this->selectOldestPreapprovalId($pendingPreapprovals);
            if ($oldestPendingId) {
                try {
                    $this->mercadoPagoClient->cancelPreapproval($oldestPendingId);
                    $canceledPreapprovals[] = $oldestPendingId;
                } catch (MercadoPagoApiException) {
                    $oldestPendingId = null;
                }
            }

            if ($oldestPendingId) {
                $link = $this->subscriptionLinkRepository->findOneBy([
                    'business' => $business,
                    'mpPreapprovalId' => $oldestPendingId,
                ]);
                if ($link instanceof MercadoPagoSubscriptionLink) {
                    $link->setStatus('CANCELLED');
                    $link->setIsPrimary(false);
                }
                $stalePendingCanceled++;
            }
        }

        $this->entityManager->flush();

        $this->ensureSingleActiveAfterMutation($business, $primaryMpPreapprovalId);

        $activeAfter = $activeBefore > 0 ? 1 : 0;

        $hasInconsistency = $activeBefore > 1 || $stalePendingCanceled > 0;

        return new ReconcileResult(
            $updatedLinks,
            [],
            $activeBefore,
            $activeAfter,
            $primaryMpPreapprovalId,
            $stalePendingCanceled,
            $hasInconsistency,
        );
    }

    /**
     * @return array<int, array{id: string, status: string|null, date_created: string|null, last_modified: string|null, reason: string|null, payer_email: string|null}>
     */
    private function fetchPreapprovalsForBusiness(Business $business): array
    {
        $externalReference = $this->externalReferenceForBusiness($business);

        try {
            return $this->mercadoPagoClient->searchPreapprovalsByExternalReference($externalReference);
        } catch (MercadoPagoApiException) {
            $preapprovals = [];
            $links = $this->subscriptionLinkRepository->findBy(['business' => $business]);
            foreach ($links as $link) {
                if (!$link instanceof MercadoPagoSubscriptionLink) {
                    continue;
                }
                try {
                    $preapproval = $this->mercadoPagoClient->getPreapproval($link->getMpPreapprovalId());
                } catch (MercadoPagoApiException) {
                    continue;
                }

                if (!is_array($preapproval) || !isset($preapproval['id'])) {
                    continue;
                }

                $preapprovals[] = [
                    'id' => (string) $preapproval['id'],
                    'status' => is_string($preapproval['status'] ?? null) ? $preapproval['status'] : null,
                    'date_created' => is_string($preapproval['date_created'] ?? null) ? $preapproval['date_created'] : null,
                    'last_modified' => is_string($preapproval['last_modified'] ?? null) ? $preapproval['last_modified'] : null,
                    'reason' => is_string($preapproval['reason'] ?? null) ? $preapproval['reason'] : null,
                    'payer_email' => is_string($preapproval['payer_email'] ?? null) ? $preapproval['payer_email'] : null,
                ];
            }

            return $preapprovals;
        }
    }

    private function externalReferenceForBusiness(Business $business): string
    {
        $businessId = $business->getId();
        if ($businessId === null) {
            throw new \RuntimeException('No se pudo determinar el comercio.');
        }

        return sprintf('business:%d', $businessId);
    }

    private function isActiveStatus(string $status): bool
    {
        return in_array($status, ['active', 'authorized'], true);
    }

    private function isPendingStatus(string $status): bool
    {
        return in_array($status, ['pending', 'in_process'], true);
    }

    private function isCancelableStatus(string $status): bool
    {
        return !in_array($status, ['cancelled', 'canceled', 'rejected', 'expired'], true);
    }

    private function normalizeStatus(?string $status): string
    {
        $status = is_string($status) ? strtolower(trim($status)) : '';

        return $status === '' ? 'unknown' : $status;
    }

    private function resolveLinkForBusiness(Business $business, string $preapprovalId, string $status): MercadoPagoSubscriptionLink
    {
        $link = $this->subscriptionLinkRepository->findOneBy([
            'business' => $business,
            'mpPreapprovalId' => $preapprovalId,
        ]);

        if (!$link instanceof MercadoPagoSubscriptionLink) {
            $link = $this->subscriptionLinkRepository->findOneBy([
                'mpPreapprovalId' => $preapprovalId,
            ]);
            if (!$link instanceof MercadoPagoSubscriptionLink) {
                $link = new MercadoPagoSubscriptionLink($preapprovalId, $status);
                $link->setBusiness($business);
                $this->entityManager->persist($link);

                return $link;
            }

            if ($link->getBusiness() !== $business) {
                $link->setBusiness($business);
            }
        }

        $link->setStatus($status);

        return $link;
    }

    /**
     * @param array<string, array{id: string, status: string|null, date_created: string|null, last_modified: string|null, reason: string|null, payer_email: string|null}> $preapprovals
     */
    private function selectMostRecentPreapprovalId(array $preapprovals): ?string
    {
        $candidateId = null;
        $candidateTimestamp = null;
        foreach ($preapprovals as $preapprovalId => $preapproval) {
            $timestamp = $this->preapprovalTimestamp($preapproval);
            if ($candidateTimestamp === null || $timestamp > $candidateTimestamp) {
                $candidateTimestamp = $timestamp;
                $candidateId = $preapprovalId;
            }
        }

        return $candidateId;
    }

    /**
     * @param array<string, array{id: string, status: string|null, date_created: string|null, last_modified: string|null, reason: string|null, payer_email: string|null}> $preapprovals
     */
    private function selectOldestPreapprovalId(array $preapprovals): ?string
    {
        $candidateId = null;
        $candidateTimestamp = null;
        foreach ($preapprovals as $preapprovalId => $preapproval) {
            $timestamp = $this->preapprovalTimestamp($preapproval);
            if ($candidateTimestamp === null || $timestamp < $candidateTimestamp) {
                $candidateTimestamp = $timestamp;
                $candidateId = $preapprovalId;
            }
        }

        return $candidateId;
    }

    /**
     * @param array{id: string, status: string|null, date_created: string|null, last_modified: string|null, reason: string|null, payer_email: string|null} $preapproval
     */
    private function preapprovalTimestamp(array $preapproval): int
    {
        $date = $preapproval['date_created'] ?? null;
        if (!is_string($date) || $date === '') {
            $date = $preapproval['last_modified'] ?? null;
        }
        if (is_string($date)) {
            try {
                return (new \DateTimeImmutable($date))->getTimestamp();
            } catch (\Throwable) {
                return 0;
            }
        }

        return 0;
    }

    private function hasActivePendingChange(Business $business): bool
    {
        $pendingChange = $this->entityManager->getRepository(PendingSubscriptionChange::class)
            ->createQueryBuilder('pendingChange')
            ->andWhere('pendingChange.business = :business')
            ->andWhere('pendingChange.status IN (:statuses)')
            ->setParameter('business', $business)
            ->setParameter('statuses', [
                PendingSubscriptionChange::STATUS_CREATED,
                PendingSubscriptionChange::STATUS_CHECKOUT_STARTED,
                PendingSubscriptionChange::STATUS_PAID,
            ])
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $pendingChange instanceof PendingSubscriptionChange;
    }

    private function markCancellationPending(Business $business, string $preapprovalId): void
    {
        $link = $this->resolveLinkForBusiness($business, $preapprovalId, 'CANCEL_PENDING');
        $link->setIsPrimary(false);
    }
}
