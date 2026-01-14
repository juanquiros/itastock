<?php

namespace App\Controller;

use App\Entity\Business;
use App\Security\BusinessContext;
use App\Service\DashboardService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

#[IsGranted('BUSINESS_SELLER')]
class DashboardController extends AbstractController
{
    public function __construct(
        private readonly DashboardService $dashboardService,
        private readonly CacheInterface $cache,
        private readonly BusinessContext $businessContext,
    ) {
    }

    #[Route('/app/dashboard', name: 'app_dashboard', methods: ['GET'])]
    public function index(): Response
    {
        $business = $this->requireBusinessContext();
        $user = $this->getUser();

        if (!$user) {
            throw new AccessDeniedException('Necesitás iniciar sesión.');
        }

        $cacheKey = sprintf('dashboard_summary_%d_%d', $business->getId(), $user->getId());
        $summary = $this->cache->get($cacheKey, function (ItemInterface $item) use ($business, $user) {
            $item->expiresAfter(10);

            return $this->dashboardService->getSummary($business, $user);
        });

        return $this->render('dashboard/index.html.twig', [
            'summary' => $summary,
            'pollMs' => 15000,
        ]);
    }

    #[Route('/app', name: 'app_dashboard_redirect', methods: ['GET'])]
    public function legacyRedirect(): RedirectResponse
    {
        return $this->redirectToRoute('app_dashboard');
    }

    private function requireBusinessContext(): Business
    {
        return $this->businessContext->requireCurrentBusiness();
    }
}
