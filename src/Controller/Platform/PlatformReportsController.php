<?php

namespace App\Controller\Platform;

use App\Repository\BillingWebhookEventRepository;
use App\Repository\PublicVisitRepository;
use App\Repository\SubscriptionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
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
        $now = new \DateTimeImmutable();
        $weekBuckets = $this->buildWeeklyPaymentBuckets($webhookEventRepository, $now, 6);
        $activeByPlan = $this->countActiveByPlan($subscriptionRepository);
        $estimatedRevenue = $this->estimateNextRevenue($subscriptionRepository);
        $graceAccounts = $this->countGraceAccounts($subscriptionRepository, $now);

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
            'weeklyPayments' => $weekBuckets,
            'activeByPlan' => $activeByPlan,
            'estimatedRevenue' => $estimatedRevenue,
            'graceAccounts' => $graceAccounts,
        ]);
    }

    #[Route('/platform/reports/traffic', name: 'platform_reports_traffic', methods: ['GET'])]
    public function traffic(Request $request, PublicVisitRepository $publicVisitRepository): Response
    {
        $range = (string) $request->query->get('range', '30d');
        $today = new \DateTimeImmutable('today');

        $from = $today->modify('-29 days');
        $to = $today->setTime(23, 59, 59);

        if ($range === '7d') {
            $from = $today->modify('-6 days');
            $to = $today->setTime(23, 59, 59);
        } elseif ($range === '90d') {
            $from = $today->modify('-89 days');
            $to = $today->setTime(23, 59, 59);
        } elseif ($range === 'custom') {
            $fromParam = $request->query->get('from');
            $toParam = $request->query->get('to');
            $customFrom = is_string($fromParam) ? \DateTimeImmutable::createFromFormat('Y-m-d', $fromParam) : false;
            $customTo = is_string($toParam) ? \DateTimeImmutable::createFromFormat('Y-m-d', $toParam) : false;

            if ($customFrom instanceof \DateTimeImmutable && $customTo instanceof \DateTimeImmutable) {
                $from = $customFrom->setTime(0, 0);
                $to = $customTo->setTime(23, 59, 59);
            } else {
                $range = '30d';
            }
        }

        if ($from > $to) {
            [$from, $to] = [$to->setTime(0, 0), $from->setTime(23, 59, 59)];
            $range = 'custom';
        }

        $totalVisits = $publicVisitRepository->countVisits($from, $to);
        $uniqueIps = $publicVisitRepository->countUniqueIps($from, $to);
        $topPages = $publicVisitRepository->topPages($from, $to);
        $topReferrers = $publicVisitRepository->topReferrers($from, $to);
        $topUtmSources = $publicVisitRepository->topUtmSources($from, $to);
        $latestVisits = $publicVisitRepository->latestVisits($from, $to);

        return $this->render('platform/reports/traffic.html.twig', [
            'range' => $range,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'totalVisits' => $totalVisits,
            'uniqueIps' => $uniqueIps,
            'topPages' => $topPages,
            'topReferrers' => $topReferrers,
            'topUtmSources' => $topUtmSources,
            'topPage' => $topPages[0] ?? null,
            'latestVisits' => $latestVisits,
        ]);
    }

    /**
     * @return array<int, array{label: string, approved: int, pending: int}>
     */
    private function buildWeeklyPaymentBuckets(
        BillingWebhookEventRepository $webhookEventRepository,
        \DateTimeImmutable $now,
        int $weeks,
    ): array {
        $start = $now->modify(sprintf('-%d weeks', $weeks - 1))->modify('monday this week')->setTime(0, 0);
        $end = $now->modify('sunday this week')->setTime(23, 59, 59);

        $events = $webhookEventRepository->createQueryBuilder('e')
            ->andWhere('e.receivedAt BETWEEN :start AND :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->orderBy('e.receivedAt', 'ASC')
            ->getQuery()
            ->getResult();

        $buckets = [];
        for ($i = 0; $i < $weeks; $i++) {
            $weekStart = $start->modify(sprintf('+%d weeks', $i));
            $weekLabel = sprintf('W%s', $weekStart->format('W'));
            $buckets[$weekLabel] = [
                'label' => $weekLabel,
                'approved' => 0,
                'pending' => 0,
            ];
        }

        foreach ($events as $event) {
            $payload = json_decode($event->getPayload(), true);
            if (!is_array($payload)) {
                continue;
            }

            if (($payload['type'] ?? null) !== 'payment' && ($payload['action'] ?? null) !== 'payment.created') {
                continue;
            }

            $payment = $payload['payment'] ?? [];
            $status = is_array($payment) ? ($payment['status'] ?? null) : null;
            if (!is_string($status)) {
                continue;
            }

            $weekLabel = $event->getReceivedAt()?->format('W');
            if (!$weekLabel) {
                continue;
            }
            $bucketKey = sprintf('W%s', $weekLabel);
            if (!isset($buckets[$bucketKey])) {
                continue;
            }

            if ($status === 'approved') {
                $buckets[$bucketKey]['approved']++;
            } else {
                $buckets[$bucketKey]['pending']++;
            }
        }

        return array_values($buckets);
    }

    /**
     * @return array<int, array{name: string, total: int}>
     */
    private function countActiveByPlan(SubscriptionRepository $subscriptionRepository): array
    {
        $rows = $subscriptionRepository->createQueryBuilder('s')
            ->select('p.name AS name, COUNT(s.id) AS total')
            ->join('s.plan', 'p')
            ->andWhere('s.status = :status')
            ->setParameter('status', 'ACTIVE')
            ->groupBy('p.id')
            ->orderBy('total', 'DESC')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row) => [
            'name' => (string) $row['name'],
            'total' => (int) $row['total'],
        ], $rows);
    }

    /**
     * @return array{count: int, amount: float}
     */
    private function estimateNextRevenue(SubscriptionRepository $subscriptionRepository): array
    {
        $rows = $subscriptionRepository->createQueryBuilder('s')
            ->select('p.priceMonthly AS price')
            ->join('s.plan', 'p')
            ->andWhere('s.status = :status')
            ->andWhere('s.nextPaymentAt IS NOT NULL')
            ->setParameter('status', 'ACTIVE')
            ->getQuery()
            ->getArrayResult();

        $amount = 0.0;
        foreach ($rows as $row) {
            $amount += (float) $row['price'];
        }

        return [
            'count' => count($rows),
            'amount' => $amount,
        ];
    }

    private function countGraceAccounts(SubscriptionRepository $subscriptionRepository, \DateTimeImmutable $now): int
    {
        return (int) $subscriptionRepository->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.status = :status')
            ->andWhere('s.nextPaymentAt IS NOT NULL')
            ->andWhere('DATE_ADD(s.nextPaymentAt, s.gracePeriodDays, \'day\') > :now')
            ->setParameter('status', 'CANCELED')
            ->setParameter('now', $now)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
