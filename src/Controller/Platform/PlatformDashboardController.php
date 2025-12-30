<?php

namespace App\Controller\Platform;

use App\Entity\Subscription;
use App\Repository\BillingPlanRepository;
use App\Repository\BillingWebhookEventRepository;
use App\Repository\SubscriptionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_PLATFORM_ADMIN')]
class PlatformDashboardController extends AbstractController
{
    #[Route('/platform', name: 'platform_dashboard', methods: ['GET'])]
    public function index(
        SubscriptionRepository $subscriptionRepository,
        BillingPlanRepository $billingPlanRepository,
        BillingWebhookEventRepository $webhookEventRepository,
    ): Response {
        $counts = $this->countSubscriptionsByStatus($subscriptionRepository);

        return $this->render('platform/dashboard/index.html.twig', [
            'counts' => $counts,
            'plansTotal' => $billingPlanRepository->count([]),
            'webhookEventsTotal' => $webhookEventRepository->count([]),
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function countSubscriptionsByStatus(SubscriptionRepository $subscriptionRepository): array
    {
        $rows = $subscriptionRepository->createQueryBuilder('s')
            ->select('s.status AS status, COUNT(s.id) AS total')
            ->groupBy('s.status')
            ->getQuery()
            ->getArrayResult();

        $counts = [
            Subscription::STATUS_TRIAL => 0,
            Subscription::STATUS_ACTIVE => 0,
            Subscription::STATUS_PAST_DUE => 0,
            Subscription::STATUS_CANCELED => 0,
            Subscription::STATUS_SUSPENDED => 0,
            Subscription::STATUS_PENDING => 0,
        ];

        foreach ($rows as $row) {
            $status = $row['status'] ?? null;
            if (is_string($status) && array_key_exists($status, $counts)) {
                $counts[$status] = (int) $row['total'];
            }
        }

        $counts['total'] = array_sum($counts);

        return $counts;
    }
}
