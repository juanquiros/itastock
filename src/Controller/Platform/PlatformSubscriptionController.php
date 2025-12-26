<?php

namespace App\Controller\Platform;

use App\Entity\Business;
use App\Entity\Subscription;
use App\Form\SubscriptionType;
use App\Repository\PlanRepository;
use App\Repository\SubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
}
