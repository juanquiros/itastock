<?php

namespace App\Controller\Platform;

use App\Entity\Business;
use App\Entity\Subscription;
use App\Form\SubscriptionType;
use App\Repository\PlanRepository;
use App\Repository\SubscriptionRepository;
use App\Service\MercadoPagoClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_PLATFORM_ADMIN')]
class PlatformSubscriptionController extends AbstractController
{
    #[Route('/platform/subscriptions', name: 'platform_subscriptions_index', methods: ['GET'])]
    public function index(Request $request, SubscriptionRepository $subscriptionRepository, PlanRepository $planRepository): Response
    {
        $status = $request->query->get('status');
        $planId = $request->query->get('plan');
        $requiresAction = $request->query->getBoolean('requires_action');
        $now = new \DateTimeImmutable();

        $qb = $subscriptionRepository->createQueryBuilder('s')
            ->leftJoin('s.plan', 'p')->addSelect('p')
            ->leftJoin('s.business', 'b')->addSelect('b')
            ->orderBy('b.name', 'ASC');

        if ($status) {
            $qb->andWhere('s.status = :status')->setParameter('status', $status);
        }
        if ($planId) {
            $qb->andWhere('p.id = :planId')->setParameter('planId', $planId);
        }
        if ($requiresAction) {
            $qb->andWhere('(s.status IN (:readonlyStatuses)) OR (s.status = :trialStatus AND s.trialEndsAt <= :now)')
                ->setParameter('readonlyStatuses', [
                    Subscription::STATUS_PAST_DUE,
                    Subscription::STATUS_SUSPENDED,
                    Subscription::STATUS_CANCELED,
                ])
                ->setParameter('trialStatus', Subscription::STATUS_TRIAL)
                ->setParameter('now', $now);
        }

        return $this->render('platform/subscriptions/index.html.twig', [
            'subscriptions' => $qb->getQuery()->getResult(),
            'filters' => ['status' => $status, 'plan' => $planId, 'requires_action' => $requiresAction],
            'plans' => $planRepository->findBy([], ['name' => 'ASC']),
            'now' => $now,
        ]);
    }

    #[Route('/platform/subscriptions/{id}/resync', name: 'platform_subscriptions_resync', methods: ['POST'])]
    public function resync(
        Subscription $subscription,
        Request $request,
        MercadoPagoClient $mercadoPagoClient,
        EntityManagerInterface $entityManager,
    ): RedirectResponse {
        if (!$this->isCsrfTokenValid('platform_subscription_action_'.$subscription->getId(), (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('platform_subscriptions_index', $request->query->all());
        }

        if (!$subscription->getMpPreapprovalId()) {
            $this->addFlash('warning', 'La suscripción no tiene preapproval ID.');

            return $this->redirectToRoute('platform_subscriptions_index', $request->query->all());
        }

        try {
            $preapproval = $mercadoPagoClient->getPreapproval($subscription->getMpPreapprovalId());
            $this->applyPreapprovalToSubscription($subscription, $preapproval);
            $entityManager->flush();
            $this->addFlash('success', 'Suscripción sincronizada.');
        } catch (\Throwable $exception) {
            $this->addFlash('danger', sprintf('No se pudo sincronizar: %s', $exception->getMessage()));
        }

        return $this->redirectToRoute('platform_subscriptions_index', $request->query->all());
    }

    #[Route('/platform/subscriptions/{id}/override', name: 'platform_subscriptions_override', methods: ['POST'])]
    public function overrideMode(
        Subscription $subscription,
        Request $request,
        EntityManagerInterface $entityManager,
    ): RedirectResponse {
        if (!$this->isCsrfTokenValid('platform_subscription_action_'.$subscription->getId(), (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('platform_subscriptions_index', $request->query->all());
        }

        $mode = $request->request->get('override_mode');
        $untilRaw = $request->request->get('override_until');

        $subscription->setOverrideMode($mode !== '' ? $mode : null);
        $subscription->setOverrideUntil($untilRaw ? new \DateTimeImmutable($untilRaw) : null);

        $entityManager->flush();
        $this->addFlash('success', 'Override actualizado.');

        return $this->redirectToRoute('platform_subscriptions_index', $request->query->all());
    }

    #[Route('/platform/businesses/{id}/subscription', name: 'platform_business_subscription', methods: ['GET', 'POST'])]
    public function manageForBusiness(
        Business $business,
        Request $request,
        EntityManagerInterface $entityManager,
    ): Response {
        $subscription = $business->getSubscription() ?: new Subscription();
        $subscription->setBusiness($business);

        $form = $this->createForm(SubscriptionType::class, $subscription);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($subscription);
            $entityManager->flush();
            $this->addFlash('success', 'Suscripción actualizada.');

            return $this->redirectToRoute('platform_business_show', ['id' => $business->getId()]);
        }

        return $this->render('platform/subscriptions/form.html.twig', [
            'form' => $form->createView(),
            'business' => $business,
        ]);
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
}
