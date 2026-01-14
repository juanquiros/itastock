<?php

namespace App\Controller;

use App\Entity\Business;
use App\Security\BusinessContext;
use App\Service\DashboardService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

#[IsGranted('BUSINESS_SELLER')]
#[Route('/app/dashboard/data', name: 'app_dashboard_data_')]
class DashboardDataController extends AbstractController
{
    public function __construct(
        private readonly DashboardService $dashboardService,
        private readonly CacheInterface $cache,
        private readonly BusinessContext $businessContext,
    ) {
    }

    #[Route('/summary', name: 'summary', methods: ['GET'])]
    public function summary(Request $request): JsonResponse
    {
        if ($request->getPreferredFormat() !== 'json' && $request->headers->get('accept') !== null && !str_contains($request->headers->get('accept'), 'application/json')) {
            return new JsonResponse(['error' => 'Solo JSON'], JsonResponse::HTTP_NOT_ACCEPTABLE);
        }

        $business = $this->requireBusinessContext();
        $user = $this->getUser();

        if (!$user) {
            throw new AccessDeniedException('Necesitás iniciar sesión.');
        }

        $cacheKey = sprintf('dashboard_summary_%d_%d', $business->getId(), $user->getId());

        $payload = $this->cache->get($cacheKey, function (ItemInterface $item) use ($business, $user) {
            $item->expiresAfter(10);

            return $this->dashboardService->getSummary($business, $user);
        });

        return new JsonResponse($payload);
    }

    private function requireBusinessContext(): Business
    {
        return $this->businessContext->requireCurrentBusiness();
    }
}
