<?php

namespace App\Controller;

use App\Entity\CashSession;
use App\Repository\CashSessionRepository;
use App\Service\PdfService;
use App\Service\ReportService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/app/admin/cash-sessions', name: 'app_admin_cash_')]
class CashSessionAdminController extends AbstractController
{
    public function __construct(
        private readonly CashSessionRepository $cashSessionRepository,
        private readonly ReportService $reportService,
        private readonly PdfService $pdfService,
    ) {
    }

    #[Route('/{id}/pdf', name: 'pdf', methods: ['GET'])]
    public function pdf(int $id): Response
    {
        $cashSession = $this->cashSessionRepository->find($id);
        $business = $this->getUser()?->getBusiness();

        if (!$cashSession instanceof CashSession || $cashSession->getBusiness() !== $business) {
            throw new AccessDeniedException('Caja no encontrada para tu comercio.');
        }

        $summary = $this->reportService->getCashSessionSummary($cashSession);

        return $this->pdfService->render('cash/pdf.html.twig', [
            'business' => $business,
            'cashSession' => $cashSession,
            'summary' => $summary,
            'generatedAt' => new \DateTimeImmutable(),
        ], sprintf('cierre-caja-%d.pdf', $cashSession->getId()));
    }
}
