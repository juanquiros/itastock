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
use Twig\Environment;

#[IsGranted('BUSINESS_ADMIN')]
#[Route('/app/admin/reports', name: 'app_reports_')]
class ReportController extends AbstractController
{
    public function __construct(
        private readonly ReportService $reportService,
        private readonly PdfService $pdfService,
        private readonly BusinessContext $businessContext,
        private readonly Environment $twig,
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

    #[Route('/discounts', name: 'discounts', methods: ['GET'])]
    public function discounts(Request $request): Response
    {
        $business = $this->requireBusinessContext();
        $fromInput = $request->query->get('from');
        $toInput = $request->query->get('to');

        $from = $fromInput ? new \DateTimeImmutable($fromInput.' 00:00:00') : new \DateTimeImmutable('first day of this month');
        $to = $toInput ? new \DateTimeImmutable($toInput.' 23:59:59') : new \DateTimeImmutable('last day of this month');

        $report = $this->reportService->getDiscountImpact($business, $from, $to);

        return $this->render('reports/discounts.html.twig', [
            'summary' => $report['summary'],
            'ranking' => $report['ranking'],
            'byPayment' => $report['byPayment'],
            'sales' => $report['sales'],
            'from' => $from,
            'to' => $to,
        ]);
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

    #[Route('/purchase-vat', name: 'purchase_vat', methods: ['GET'])]
    public function purchaseVat(Request $request): Response
    {
        $business = $this->requireBusinessContext();
        $fromInput = $request->query->get('from');
        $toInput = $request->query->get('to');

        $from = $fromInput ? new \DateTimeImmutable($fromInput) : new \DateTimeImmutable('first day of this month');
        $to = $toInput ? new \DateTimeImmutable($toInput) : new \DateTimeImmutable('last day of this month');

        $report = $this->reportService->getPurchaseVatReport($business, $from, $to);

        $template = <<<'TWIG'
{% extends 'base.html.twig' %}

{% block title %}IVA compras · ItaStock{% endblock %}

{% block body %}
    <div class="mb-4">
        <p class="text-uppercase text-muted mb-1 small fw-semibold">Reportes</p>
        <h1 class="h4 mb-0">IVA compras</h1>
        <p class="text-secondary mb-0">Periodo: {{ from|date('d/m/Y') }} - {{ to|date('d/m/Y') }}</p>
    </div>

    <form class="row g-2 mb-4" method="get">
        <div class="col-md-3">
            <label class="form-label">Desde</label>
            <input class="form-control" type="date" name="from" value="{{ from|date('Y-m-d') }}">
        </div>
        <div class="col-md-3">
            <label class="form-label">Hasta</label>
            <input class="form-control" type="date" name="to" value="{{ to|date('Y-m-d') }}">
        </div>
        <div class="col-md-3 align-self-end">
            <button class="btn btn-primary">Actualizar</button>
        </div>
    </form>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <div class="row text-center">
                <div class="col-md-4">
                    <p class="text-muted small mb-1">Neto</p>
                    <p class="h5 mb-0">{{ report.summary.net|number_format(2, '.', ',') }}</p>
                </div>
                <div class="col-md-4">
                    <p class="text-muted small mb-1">IVA</p>
                    <p class="h5 mb-0">{{ report.summary.iva|number_format(2, '.', ',') }}</p>
                </div>
                <div class="col-md-4">
                    <p class="text-muted small mb-1">Total</p>
                    <p class="h5 mb-0">{{ report.summary.total|number_format(2, '.', ',') }}</p>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Proveedor</th>
                            <th>Comprobante</th>
                            <th class="text-end">Neto</th>
                            <th class="text-end">IVA</th>
                            <th class="text-end">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        {% for invoice in report.invoices %}
                            <tr>
                                <td>{{ invoice.invoiceDate|date('d/m/Y') }}</td>
                                <td>{{ invoice.supplierName }}</td>
                                <td>{{ invoice.invoiceType }} {{ invoice.pointOfSale ?: '' }} {{ invoice.invoiceNumber }}</td>
                                <td class="text-end">{{ invoice.netAmount|number_format(2, '.', ',') }}</td>
                                <td class="text-end">{{ invoice.ivaAmount|number_format(2, '.', ',') }}</td>
                                <td class="text-end">{{ invoice.totalAmount|number_format(2, '.', ',') }}</td>
                            </tr>
                        {% else %}
                            <tr>
                                <td colspan="6" class="text-muted">No hay compras confirmadas en el período.</td>
                            </tr>
                        {% endfor %}
                    </tbody>
                </table>
            </div>
        </div>
    </div>
{% endblock %}
TWIG;

        return new Response($this->twig->createTemplate($template)->render([
            'report' => $report,
            'from' => $from,
            'to' => $to,
        ]));
    }

    #[Route('/purchase-suppliers', name: 'purchase_suppliers', methods: ['GET'])]
    public function purchaseSuppliers(Request $request): Response
    {
        $business = $this->requireBusinessContext();
        $fromInput = $request->query->get('from');
        $toInput = $request->query->get('to');

        $from = $fromInput ? new \DateTimeImmutable($fromInput) : new \DateTimeImmutable('first day of this month');
        $to = $toInput ? new \DateTimeImmutable($toInput) : new \DateTimeImmutable('last day of this month');

        $report = $this->reportService->getPurchaseTotalsBySupplier($business, $from, $to);

        $template = <<<'TWIG'
{% extends 'base.html.twig' %}

{% block title %}Compras por proveedor · ItaStock{% endblock %}

{% block body %}
    <div class="mb-4">
        <p class="text-uppercase text-muted mb-1 small fw-semibold">Reportes</p>
        <h1 class="h4 mb-0">Total comprado por proveedor</h1>
        <p class="text-secondary mb-0">Periodo: {{ from|date('d/m/Y') }} - {{ to|date('d/m/Y') }}</p>
    </div>

    <form class="row g-2 mb-4" method="get">
        <div class="col-md-3">
            <label class="form-label">Desde</label>
            <input class="form-control" type="date" name="from" value="{{ from|date('Y-m-d') }}">
        </div>
        <div class="col-md-3">
            <label class="form-label">Hasta</label>
            <input class="form-control" type="date" name="to" value="{{ to|date('Y-m-d') }}">
        </div>
        <div class="col-md-3 align-self-end">
            <button class="btn btn-primary">Actualizar</button>
        </div>
    </form>

    <div class="card shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr>
                            <th>Proveedor</th>
                            <th class="text-end">Facturas</th>
                            <th class="text-end">Total comprado</th>
                        </tr>
                    </thead>
                    <tbody>
                        {% for row in report %}
                            <tr>
                                <td>{{ row.supplierName }}</td>
                                <td class="text-end">{{ row.invoicesCount }}</td>
                                <td class="text-end">{{ row.total|number_format(2, '.', ',') }}</td>
                            </tr>
                        {% else %}
                            <tr>
                                <td colspan="3" class="text-muted">No hay compras confirmadas en el período.</td>
                            </tr>
                        {% endfor %}
                    </tbody>
                </table>
            </div>
        </div>
    </div>
{% endblock %}
TWIG;

        return new Response($this->twig->createTemplate($template)->render([
            'report' => $report,
            'from' => $from,
            'to' => $to,
        ]));
    }

    private function requireBusinessContext(): Business
    {
        return $this->businessContext->requireCurrentBusiness();
    }
}
