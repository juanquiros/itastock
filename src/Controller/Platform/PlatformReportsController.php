<?php

namespace App\Controller\Platform;

use App\Repository\BillingWebhookEventRepository;
use App\Repository\SubscriptionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_PLATFORM_ADMIN')]
class PlatformReportsController extends AbstractController
{
    #[Route('/platform/reports', name: 'platform_reports_index', methods: ['GET'])]
    public function index(
        SubscriptionRepository $subscriptionRepository,
        BillingWebhookEventRepository $webhookEventRepository,
    ): Response {
        $processedEvents = $webhookEventRepository->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->andWhere('e.processedAt IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();

        $pendingEvents = $webhookEventRepository->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->andWhere('e.processedAt IS NULL')
            ->getQuery()
            ->getSingleScalarResult();

        return $this->render('platform/reports/index.html.twig', [
            'subscriptionsTotal' => $subscriptionRepository->count([]),
            'webhookProcessed' => (int) $processedEvents,
            'webhookPending' => (int) $pendingEvents,
        ]);
    }
}
