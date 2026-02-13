<?php

namespace App\Controller;

use App\Entity\Business;
use App\Entity\Sale;
use App\Repository\SaleRepository;
use App\Security\BusinessContext;
use App\Service\PdfService;
use App\Service\ReportService;
use App\Service\SaleVoidService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('BUSINESS_ADMIN')]
#[Route('/app/admin/sales', name: 'app_admin_sales_')]
class SalesAdminController extends AbstractController
{
    public function __construct(
        private readonly SaleRepository $saleRepository,
        private readonly ReportService $reportService,
        private readonly PdfService $pdfService,
        private readonly SaleVoidService $saleVoidService,
        private readonly BusinessContext $businessContext,
    )
    {
    }

    #[Route('/export.{_format}', name: 'export', defaults: ['_format' => 'html'], requirements: ['_format' => 'html|csv'], methods: ['GET'])]
    public function export(Request $request): Response
    {
        $business = $this->requireBusinessContext();

        $fromInput = trim((string) $request->query->get('from', ''));
        $toInput = trim((string) $request->query->get('to', ''));
        $fromInput = $fromInput !== '' ? $fromInput : null;
        $toInput = $toInput !== '' ? $toInput : null;
        $errors = [];

        $fromDate = $fromInput ? \DateTimeImmutable::createFromFormat('Y-m-d', $fromInput) : null;
        $toDate = $toInput ? \DateTimeImmutable::createFromFormat('Y-m-d', $toInput) : null;

        if ($fromInput !== null && $fromDate === false) {
            $errors[] = 'Fecha desde inválida.';
        }

        if ($toInput !== null && $toDate === false) {
            $errors[] = 'Fecha hasta inválida.';
        }

        if ($fromDate !== null && $toDate !== null && $fromDate > $toDate) {
            $errors[] = 'La fecha desde debe ser menor o igual a la fecha hasta.';
        }

        if ($request->getRequestFormat() !== 'csv' || $fromDate === null || $toDate === null || $errors !== []) {
            return $this->render('sale/export.html.twig', [
                'errors' => $errors,
                'filters' => [
                    'from' => $fromInput,
                    'to' => $toInput,
                ],
            ]);
        }

        $fromDate = $fromDate->setTime(0, 0, 0);
        $toDate = $toDate->setTime(23, 59, 59);

        $filters = [
            'seller' => $request->query->get('seller'),
            'method' => $request->query->get('method'),
            'customerId' => $request->query->get('customer'),
        ];

        $rows = $this->reportService->getSalesForRange($business, $fromDate, $toDate, $filters);

        $response = new StreamedResponse();
        $response->setCallback(static function () use ($rows): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['date', 'saleId', 'sellerEmail', 'customerName', 'paymentMethod', 'status', 'total', 'itemsCount'], ';');

            foreach ($rows as $row) {
                $createdAt = $row['createdAt'];
                $dateValue = $createdAt instanceof \DateTimeInterface ? $createdAt->format('Y-m-d H:i:s') : '';

                fputcsv($handle, [
                    $dateValue,
                    $row['saleId'],
                    $row['sellerEmail'],
                    $row['customerName'],
                    $row['paymentMethod'],
                    $row['saleStatus'],
                    number_format((float) $row['total'], 2, '.', ''),
                    $row['itemsCount'],
                ], ';');
            }

            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="sales.csv"');

        return $response;
    }

    #[Route('/pdf', name: 'pdf', methods: ['GET'])]
    public function pdf(Request $request): Response
    {
        $business = $this->requireBusinessContext();

        $fromInput = trim((string) $request->query->get('from', ''));
        $toInput = trim((string) $request->query->get('to', ''));
        $fromInput = $fromInput !== '' ? $fromInput : null;
        $toInput = $toInput !== '' ? $toInput : null;
        $errors = [];

        $fromDate = $fromInput ? \DateTimeImmutable::createFromFormat('Y-m-d', $fromInput) : null;
        $toDate = $toInput ? \DateTimeImmutable::createFromFormat('Y-m-d', $toInput) : null;

        if ($fromInput !== null && $fromDate === false) {
            $errors[] = 'Fecha desde inválida.';
        }

        if ($toInput !== null && $toDate === false) {
            $errors[] = 'Fecha hasta inválida.';
        }

        if ($fromDate !== null && $toDate !== null && $fromDate > $toDate) {
            $errors[] = 'La fecha desde debe ser menor o igual a la fecha hasta.';
        }

        if ($fromDate === null && $toDate === null && $errors === []) {
            $today = new \DateTimeImmutable('today');
            $fromDate = $today;
            $toDate = $today;
        }

        if ($fromDate === null || $toDate === null || $errors !== []) {
            return $this->render('sale/export.html.twig', [
                'errors' => $errors,
                'filters' => [
                    'from' => $fromInput,
                    'to' => $toInput,
                ],
            ]);
        }

        $fromDate = $fromDate->setTime(0, 0, 0);
        $toDate = $toDate->setTime(23, 59, 59);

        $filters = [
            'seller' => $request->query->get('seller'),
            'method' => $request->query->get('method'),
            'customerId' => $request->query->get('customer'),
        ];

        $rows = $this->reportService->getSalesForRange($business, $fromDate, $toDate, $filters);
        $total = array_reduce($rows, static fn ($carry, $row) => $carry + (float) $row['total'], 0.0);

        @set_time_limit(120);

        return $this->pdfService->render('reports/sales_pdf.html.twig', [
            'business' => $business,
            'rows' => $rows,
            'from' => $fromDate,
            'to' => $toDate,
            'generatedAt' => new \DateTimeImmutable(),
            'total' => number_format($total, 2, '.', ''),
        ], 'ventas.pdf');
    }

    #[Route('/{id}/void', name: 'void', methods: ['POST'])]
    public function voidSale(Request $request, Sale $sale): Response
    {
        $business = $this->requireBusinessContext();
        $user = $this->getUser();

        if (!$user instanceof \App\Entity\User) {
            throw new AccessDeniedException('Debés iniciar sesión para anular ventas.');
        }

        if ($sale->getBusiness() !== $business) {
            throw new AccessDeniedException('No podés anular ventas de otro comercio.');
        }

        if (!$this->isCsrfTokenValid('void_sale_'.$sale->getId(), (string) $request->request->get('_token'))) {
            throw new AccessDeniedException('Token CSRF inválido.');
        }

        $reason = trim((string) $request->request->get('reason'));
        if ($reason === '') {
            $this->addFlash('danger', 'El motivo es obligatorio.');

            return $this->redirectToRoute('app_sale_ticket', ['id' => $sale->getId()]);
        }

        try {
            $this->saleVoidService->voidSale($sale, $user, $reason);
            $this->addFlash('success', 'Venta anulada correctamente.');
        } catch (\DomainException $exception) {
            $this->addFlash('danger', $exception->getMessage());
        }

        return $this->redirectToRoute('app_sale_ticket', ['id' => $sale->getId()]);
    }

    private function requireBusinessContext(): Business
    {
        return $this->businessContext->requireCurrentBusiness();
    }
}
