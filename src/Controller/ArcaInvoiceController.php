<?php

namespace App\Controller;

use App\Entity\ArcaInvoice;
use App\Entity\Business;
use App\Repository\ArcaInvoiceRepository;
use App\Repository\BusinessUserRepository;
use App\Security\BusinessContext;
use App\Service\PdfService;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('BUSINESS_ADMIN')]
#[Route('/app/admin/arca', name: 'app_admin_arca_')]
class ArcaInvoiceController extends AbstractController
{
    public function __construct(
        private readonly BusinessContext $businessContext,
        private readonly ArcaInvoiceRepository $arcaInvoiceRepository,
        private readonly BusinessUserRepository $businessUserRepository,
        private readonly PdfService $pdfService,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $business = $this->requireBusinessContext();
        $filters = $this->resolveFilters($request);

        $qb = $this->arcaInvoiceRepository->createQueryBuilder('invoice')
            ->andWhere('invoice.business = :business')
            ->setParameter('business', $business)
            ->orderBy('invoice.createdAt', 'DESC');

        if ($filters['status']) {
            $qb->andWhere('invoice.status = :status')
                ->setParameter('status', $filters['status']);
        }

        if ($filters['posNumber']) {
            $qb->andWhere('invoice.arcaPosNumber = :pos')
                ->setParameter('pos', $filters['posNumber']);
        }

        if ($filters['createdBy']) {
            $qb->andWhere('invoice.createdBy = :user')
                ->setParameter('user', $filters['createdBy']);
        }

        if ($filters['from']) {
            $qb->andWhere('invoice.createdAt >= :from')
                ->setParameter('from', $filters['from']);
        }

        if ($filters['to']) {
            $qb->andWhere('invoice.createdAt <= :to')
                ->setParameter('to', $filters['to']);
        }

        $invoices = $qb->getQuery()->getResult();

        if ($filters['exportCsv']) {
            return $this->exportCsv($invoices);
        }

        $exportParams = array_filter([
            'status' => $filters['status'],
            'pos' => $filters['posNumber'],
            'user' => $filters['createdBy'],
            'from' => $filters['from']?->format('Y-m-d'),
            'to' => $filters['to']?->format('Y-m-d'),
            'export' => 'csv',
        ], static fn ($value) => $value !== null && $value !== '');

        return $this->render('arca/index.html.twig', [
            'invoices' => $invoices,
            'filters' => $filters,
            'exportParams' => $exportParams,
            'statuses' => [
                ArcaInvoice::STATUS_DRAFT,
                ArcaInvoice::STATUS_REQUESTED,
                ArcaInvoice::STATUS_AUTHORIZED,
                ArcaInvoice::STATUS_REJECTED,
                ArcaInvoice::STATUS_CANCELLED,
            ],
            'memberships' => $this->businessUserRepository->findBy(['business' => $business, 'isActive' => true], ['createdAt' => 'ASC']),
        ]);
    }

    #[Route('/{id}/detail', name: 'detail', methods: ['GET'])]
    public function detail(ArcaInvoice $invoice): Response
    {
        $business = $this->requireBusinessContext();
        $this->denyIfDifferentBusiness($invoice, $business);

        return $this->render('arca/detail.html.twig', [
            'invoice' => $invoice,
        ]);
    }

    #[Route('/{id}/pdf', name: 'pdf', methods: ['GET'])]
    public function pdf(ArcaInvoice $invoice): Response
    {
        $business = $this->requireBusinessContext();
        $this->denyIfDifferentBusiness($invoice, $business);

        return $this->pdfService->render('arca/invoice_pdf.html.twig', [
            'invoice' => $invoice,
            'business' => $business,
            'sale' => $invoice->getSale(),
            'generatedAt' => new DateTimeImmutable(),
        ], sprintf('factura-venta-%d.pdf', $invoice->getSale()->getId()));
    }

    private function requireBusinessContext(): Business
    {
        return $this->businessContext->requireCurrentBusiness();
    }

    private function denyIfDifferentBusiness(ArcaInvoice $invoice, Business $business): void
    {
        if ($invoice->getBusiness() !== $business) {
            throw $this->createAccessDeniedException('Solo podés ver facturas de tu comercio.');
        }
    }

    /**
     * @return array{status: ?string, posNumber: ?int, createdBy: ?int, from: ?DateTimeImmutable, to: ?DateTimeImmutable, exportCsv: bool}
     */
    private function resolveFilters(Request $request): array
    {
        $status = $request->query->get('status');
        $posNumber = $request->query->getInt('pos');
        $createdBy = $request->query->getInt('user');
        $fromRaw = $request->query->get('from');
        $toRaw = $request->query->get('to');

        $from = $fromRaw ? DateTimeImmutable::createFromFormat('Y-m-d', $fromRaw) ?: null : null;
        $to = $toRaw ? DateTimeImmutable::createFromFormat('Y-m-d', $toRaw) ?: null : null;
        if ($to) {
            $to = $to->setTime(23, 59, 59);
        }

        return [
            'status' => $status ?: null,
            'posNumber' => $posNumber > 0 ? $posNumber : null,
            'createdBy' => $createdBy > 0 ? $createdBy : null,
            'from' => $from,
            'to' => $to,
            'exportCsv' => $request->query->get('export') === 'csv',
        ];
    }

    /**
     * @param ArcaInvoice[] $invoices
     */
    private function exportCsv(array $invoices): Response
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, [
            'Fecha',
            'Venta',
            'Cliente',
            'Total',
            'Neto',
            'IVA',
            'CAE',
            'Vto CAE',
            'Estado',
            'Punto de venta',
        ]);

        foreach ($invoices as $invoice) {
            $sale = $invoice->getSale();
            $customer = $sale->getCustomer();
            fputcsv($handle, [
                $invoice->getCreatedAt()?->format('d/m/Y H:i') ?? '',
                $sale?->getId(),
                $customer?->getName() ?? 'Consumidor Final',
                $invoice->getTotalAmount(),
                $invoice->getNetAmount(),
                $invoice->getVatAmount(),
                $invoice->getCae(),
                $invoice->getCaeDueDate()?->format('d/m/Y') ?? '',
                $invoice->getStatus(),
                $invoice->getArcaPosNumber(),
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        $response = new Response($csv ?? '');
        $disposition = $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'reporte-facturacion-arca.csv');
        $response->headers->set('Content-Type', 'text/csv');
        $response->headers->set('Content-Disposition', $disposition);

        return $response;
    }
}
