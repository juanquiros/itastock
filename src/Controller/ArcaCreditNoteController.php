<?php

namespace App\Controller;

use App\Entity\Business;
use App\Entity\Sale;
use App\Entity\User;
use App\Repository\ArcaCreditNoteRepository;
use App\Repository\ArcaInvoiceRepository;
use App\Repository\BusinessArcaConfigRepository;
use App\Repository\BusinessUserRepository;
use App\Security\BusinessContext;
use App\Service\ArcaCreditNoteService;
use App\Service\SaleVoidService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('BUSINESS_ADMIN')]
#[Route('/app/admin/sales', name: 'app_admin_sales_')]
class ArcaCreditNoteController extends AbstractController
{
    public function __construct(
        private readonly BusinessContext $businessContext,
        private readonly BusinessUserRepository $businessUserRepository,
        private readonly BusinessArcaConfigRepository $arcaConfigRepository,
        private readonly ArcaInvoiceRepository $arcaInvoiceRepository,
        private readonly ArcaCreditNoteRepository $arcaCreditNoteRepository,
        private readonly ArcaCreditNoteService $arcaCreditNoteService,
        private readonly SaleVoidService $saleVoidService,
    ) {
    }

    #[Route('/{id}/credit-note/issue', name: 'credit_note_issue', methods: ['POST'])]
    public function issue(Request $request, Sale $sale): Response
    {
        $business = $this->requireBusinessContext();
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw new AccessDeniedException('Debés iniciar sesión para emitir notas de crédito.');
        }

        if ($sale->getBusiness() !== $business) {
            throw new AccessDeniedException('No podés emitir notas de crédito de otro comercio.');
        }

        if (!$this->isCsrfTokenValid('credit_note_'.$sale->getId(), (string) $request->request->get('_token'))) {
            throw new AccessDeniedException('Token CSRF inválido.');
        }

        if ($sale->getStatus() !== Sale::STATUS_CONFIRMED) {
            $this->addFlash('danger', 'La venta ya fue anulada.');

            return $this->redirectToRoute('app_sale_ticket', ['id' => $sale->getId()]);
        }

        $invoice = $this->arcaInvoiceRepository->findOneBy([
            'business' => $business,
            'sale' => $sale,
        ]);

        if (!$invoice || $invoice->getStatus() !== \App\Entity\ArcaInvoice::STATUS_AUTHORIZED) {
            $this->addFlash('danger', 'La venta no tiene factura autorizada para emitir nota de crédito.');

            return $this->redirectToRoute('app_sale_ticket', ['id' => $sale->getId()]);
        }

        $existing = $this->arcaCreditNoteRepository->findOneBy([
            'business' => $business,
            'sale' => $sale,
        ]);

        if ($existing) {
            $this->addFlash('warning', 'Ya existe una nota de crédito asociada a esta venta.');

            return $this->redirectToRoute('app_sale_ticket', ['id' => $sale->getId()]);
        }

        $membership = $this->businessUserRepository->findActiveMembership($user, $business);
        $arcaConfig = $this->arcaConfigRepository->findOneBy(['business' => $business]);

        if (!$membership || !$arcaConfig?->isArcaEnabled()) {
            $this->addFlash('danger', 'Configurá la facturación ARCA para emitir la nota de crédito.');

            return $this->redirectToRoute('app_sale_ticket', ['id' => $sale->getId()]);
        }

        if (!$membership->isArcaEnabledForThisCashier() || $membership->getArcaMode() !== 'INVOICE' || $membership->getArcaPosNumber() === null) {
            $this->addFlash('danger', 'La caja no está habilitada para emitir comprobantes ARCA.');

            return $this->redirectToRoute('app_sale_ticket', ['id' => $sale->getId()]);
        }

        $reason = trim((string) $request->request->get('reason', ''));
        $creditNote = $this->arcaCreditNoteService->createForSale($sale, $invoice, $arcaConfig, $membership, $user, $reason);
        $this->arcaCreditNoteService->requestCae($creditNote, $invoice, $arcaConfig);

        if ($creditNote->getStatus() === \App\Entity\ArcaCreditNote::STATUS_AUTHORIZED) {
            $voidReason = trim(sprintf('NC emitida%s', $reason !== '' ? ': '.$reason : ''));
            try {
                $this->saleVoidService->voidSaleAfterCreditNote($sale, $user, $voidReason);
                $this->addFlash('success', 'Nota de crédito autorizada y venta anulada.');
            } catch (\DomainException $exception) {
                $this->addFlash('warning', sprintf('NC autorizada, pero no se pudo anular la venta: %s', $exception->getMessage()));
            }
        } else {
            $this->addFlash('warning', 'No se pudo autorizar la nota de crédito. Revisá el ticket.');
        }

        return $this->redirectToRoute('app_sale_ticket', ['id' => $sale->getId()]);
    }

    private function requireBusinessContext(): Business
    {
        return $this->businessContext->requireCurrentBusiness();
    }
}
