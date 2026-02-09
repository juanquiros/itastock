<?php

namespace App\Controller;

use App\Entity\CashSession;
use App\Entity\Sale;
use App\Repository\ArcaInvoiceRepository;
use App\Repository\BusinessArcaConfigRepository;
use App\Repository\BusinessUserRepository;
use App\Repository\CashSessionRepository;
use App\Repository\SaleRepository;
use App\Security\BusinessContext;
use App\Service\ArcaInvoiceService;
use App\Service\PdfService;
use App\Service\ReportService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('BUSINESS_ADMIN')]
#[Route('/app/admin/cash-sessions', name: 'app_admin_cash_')]
class CashSessionAdminController extends AbstractController
{
    public function __construct(
        private readonly CashSessionRepository $cashSessionRepository,
        private readonly ReportService $reportService,
        private readonly PdfService $pdfService,
        private readonly BusinessContext $businessContext,
        private readonly SaleRepository $saleRepository,
        private readonly BusinessUserRepository $businessUserRepository,
        private readonly BusinessArcaConfigRepository $arcaConfigRepository,
        private readonly ArcaInvoiceRepository $arcaInvoiceRepository,
        private readonly ArcaInvoiceService $arcaInvoiceService,
    ) {
    }

    #[Route('/{id}/pdf', name: 'pdf', methods: ['GET'])]
    public function pdf(Request $request, int $id): Response
    {
        $cashSession = $this->cashSessionRepository->find($id);
        $business = $this->businessContext->requireCurrentBusiness();

        if (!$cashSession instanceof CashSession || $cashSession->getBusiness() !== $business) {
            throw new AccessDeniedException('Caja no encontrada para tu comercio.');
        }

        $includeDetail = $request->query->getBoolean('detail');
        $summary = $this->reportService->getCashSessionSummary($cashSession, $includeDetail);

        return $this->pdfService->render('cash/pdf.html.twig', [
            'business' => $business,
            'cashSession' => $cashSession,
            'summary' => $summary,
            'generatedAt' => new \DateTimeImmutable(),
            'includeDetail' => $includeDetail,
        ], sprintf('cierre-caja-%d.pdf', $cashSession->getId()));
    }

    #[Route('/sales/{saleId}/arca/issue', name: 'issue_arca', methods: ['POST'])]
    public function issueArcaFromCashReport(Request $request, int $saleId): RedirectResponse
    {
        $business = $this->businessContext->requireCurrentBusiness();
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            throw new AccessDeniedException('Debés iniciar sesión para operar.');
        }

        $sale = $this->saleRepository->find($saleId);
        if (!$sale instanceof Sale || $sale->getBusiness() !== $business) {
            throw new AccessDeniedException('Venta inválida para tu comercio.');
        }

        $cashSessionId = $request->request->getInt('cash_session_id');
        $redirectParams = $cashSessionId > 0 ? ['id' => $cashSessionId] : null;

        if (!$this->isCsrfTokenValid('arca_invoice_cash_'.$sale->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');

            return $redirectParams
                ? $this->redirectToRoute('app_cash_report', $redirectParams)
                : $this->redirectToRoute('app_cash_status');
        }

        if ($sale->getStatus() !== Sale::STATUS_CONFIRMED) {
            $this->addFlash('danger', 'Solo podés facturar ventas confirmadas.');

            return $redirectParams
                ? $this->redirectToRoute('app_cash_report', $redirectParams)
                : $this->redirectToRoute('app_cash_status');
        }

        $arcaConfig = $this->arcaConfigRepository->findOneBy(['business' => $business]);
        if (!$arcaConfig || !$arcaConfig->isArcaEnabled()) {
            $this->addFlash('danger', 'ARCA no está habilitado para este comercio.');

            return $redirectParams
                ? $this->redirectToRoute('app_cash_report', $redirectParams)
                : $this->redirectToRoute('app_cash_status');
        }

        $existing = $this->arcaInvoiceRepository->findOneBy(['business' => $business, 'sale' => $sale]);
        if ($existing) {
            $this->addFlash('warning', 'Esta venta ya tiene una factura asociada.');

            return $redirectParams
                ? $this->redirectToRoute('app_cash_report', $redirectParams)
                : $this->redirectToRoute('app_cash_status');
        }

        $seller = $sale->getCreatedBy();
        $membership = $seller ? $this->businessUserRepository->findActiveMembership($seller, $business) : null;

        if (!$membership || !$membership->isArcaEnabledForThisCashier() || $membership->getArcaMode() !== 'INVOICE') {
            $this->addFlash('danger', 'La caja del vendedor no está habilitada para facturar.');

            return $redirectParams
                ? $this->redirectToRoute('app_cash_report', $redirectParams)
                : $this->redirectToRoute('app_cash_status');
        }

        if ($membership->getArcaPosNumber() === null) {
            $this->addFlash('danger', 'La caja del vendedor no tiene punto de venta ARCA definido.');

            return $redirectParams
                ? $this->redirectToRoute('app_cash_report', $redirectParams)
                : $this->redirectToRoute('app_cash_status');
        }

        $priceMode = (string) $request->request->get('price_mode', ArcaInvoiceService::PRICE_MODE_HISTORIC);
        if (!in_array($priceMode, [ArcaInvoiceService::PRICE_MODE_HISTORIC, ArcaInvoiceService::PRICE_MODE_CURRENT], true)) {
            $priceMode = ArcaInvoiceService::PRICE_MODE_HISTORIC;
        }

        $invoice = $this->arcaInvoiceService->buildInvoiceFromSale($sale, $user, $membership, $arcaConfig, $priceMode);
        $this->arcaInvoiceService->requestCae($invoice, $arcaConfig);

        if ($invoice->getStatus() === \App\Entity\ArcaInvoice::STATUS_AUTHORIZED) {
            $this->addFlash('success', 'Factura autorizada correctamente.');
        } else {
            $this->addFlash('danger', 'No se pudo autorizar la factura. Revisá el detalle en reportes.');
        }

        return $redirectParams
            ? $this->redirectToRoute('app_cash_report', $redirectParams)
            : $this->redirectToRoute('app_cash_status');
    }
}
