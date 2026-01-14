<?php

namespace App\Controller;

use App\Entity\Business;
use App\Security\BusinessContext;
use App\Service\ReportService;
use App\Service\PdfService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('BUSINESS_ADMIN')]
#[Route('/app/admin/reports', name: 'app_reports_')]
class ReportController extends AbstractController
{
    public function __construct(
        private readonly ReportService $reportService,
        private readonly PdfService $pdfService,
        private readonly BusinessContext $businessContext,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('reports/index.html.twig');
    }

    #[Route('/debtors', name: 'debtors', methods: ['GET'])]
    public function debtors(Request $request): Response
    {
        $business = $this->requireBusinessContext();
        $minBalance = max(0, (float) $request->query->get('min_balance', 0));

        $report = $this->reportService->getDebtors($business, $minBalance);

        return $this->render('reports/debtors.html.twig', [
            'items' => $report,
            'minBalance' => $minBalance,
        ]);
    }

    #[Route('/debtors/pdf', name: 'debtors_pdf', methods: ['GET'])]
    public function debtorsPdf(Request $request): Response
    {
        $business = $this->requireBusinessContext();
        $minBalance = max(0, (float) $request->query->get('min', 0));
        $report = $this->reportService->getDebtors($business, $minBalance);
        $totalDebt = array_reduce($report, static fn ($carry, $row) => $carry + $row['balance'], 0.0);

        return $this->pdfService->render('reports/debtors_pdf.html.twig', [
            'business' => $business,
            'items' => $report,
            'minBalance' => $minBalance,
            'generatedAt' => new \DateTimeImmutable(),
            'totalDebt' => number_format($totalDebt, 2, '.', ''),
        ], 'deudores.pdf');
    }

    #[Route('/stock-low/pdf', name: 'stock_low_pdf', methods: ['GET'])]
    public function stockLowPdf(): Response
    {
        $business = $this->requireBusinessContext();
        $products = $this->reportService->getLowStockProducts($business);

        return $this->pdfService->render('reports/stock_low_pdf.html.twig', [
            'business' => $business,
            'products' => $products,
            'generatedAt' => new \DateTimeImmutable(),
        ], 'stock-bajo.pdf');
    }

    private function requireBusinessContext(): Business
    {
        return $this->businessContext->requireCurrentBusiness();
    }
}
