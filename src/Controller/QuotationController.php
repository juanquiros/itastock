<?php

namespace App\Controller;

use App\Entity\Business;
use App\Entity\Product;
use App\Entity\Quotation;
use App\Repository\QuotationRepository;
use App\Security\BusinessContext;
use App\Service\PdfService;
use App\Service\PricingService;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('BUSINESS_SELLER')]
#[Route('/app/quotations', name: 'app_quotation_')]
class QuotationController extends AbstractController
{
    public function __construct(
        private readonly BusinessContext $businessContext,
        private readonly QuotationRepository $quotationRepository,
        private readonly PricingService $pricingService,
        private readonly PdfService $pdfService,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $business = $this->requireBusiness();
        $page = max(1, $request->query->getInt('page', 1));
        $perPage = 20;
        $query = trim((string) $request->query->get('q', ''));

        $qb = $this->quotationRepository->createSearchQueryBuilder($business, $query);

        $qb->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage);
        $paginator = new Paginator($qb);

        return $this->render('quotation/index.html.twig', [
            'quotations' => iterator_to_array($paginator),
            'page' => $page,
            'pages' => max(1, (int) ceil($paginator->count() / $perPage)),
            'query' => $query,
        ]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(Quotation $quotation): Response
    {
        $business = $this->requireBusiness();
        $this->denyIfDifferentBusiness($quotation, $business);

        $alertsByItem = [];
        $hasDifferences = false;

        foreach ($quotation->getItems() as $item) {
            $alerts = [];
            $product = $item->getProduct();

            if ($product instanceof Product) {
                if (bccomp($product->getStock(), $item->getQty(), 3) < 0) {
                    $alerts[] = 'Stock insuficiente';
                }

                $currentPrice = number_format($this->pricingService->resolveUnitPrice($product, $quotation->getCustomer()), 2, '.', '');
                if (bccomp($currentPrice, $item->getUnitPrice(), 2) !== 0) {
                    $alerts[] = 'Precio actualizado';
                }

                if (!$product->isActive()) {
                    $alerts[] = 'Producto inactivo';
                }
            }

            if ($alerts !== []) {
                $hasDifferences = true;
            }

            $alertsByItem[$item->getId() ?? spl_object_id($item)] = $alerts;
        }

        return $this->render('quotation/show.html.twig', [
            'quotation' => $quotation,
            'alertsByItem' => $alertsByItem,
            'hasDifferences' => $hasDifferences,
        ]);
    }

    #[Route('/{id}/pdf', name: 'pdf', methods: ['GET'])]
    public function pdf(Quotation $quotation): Response
    {
        $business = $this->requireBusiness();
        $this->denyIfDifferentBusiness($quotation, $business);

        return $this->pdfService->render('quotation/quotation_pdf.html.twig', [
            'quotation' => $quotation,
            'business' => $business,
            'generatedAt' => new \DateTimeImmutable(),
        ], sprintf('presupuesto-%s.pdf', $quotation->getCommercialNumber()));
    }

    #[Route('/{id}/to-sale', name: 'to_sale', methods: ['POST'])]
    public function toSale(Request $request, Quotation $quotation): Response
    {
        $business = $this->requireBusiness();
        $this->denyIfDifferentBusiness($quotation, $business);

        if (!$this->isCsrfTokenValid('quotation_to_sale_'.$quotation->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'No se pudo validar la conversión del presupuesto.');

            return $this->redirectToRoute('app_quotation_show', ['id' => $quotation->getId()]);
        }

        $payloadItems = [];
        $omittedCount = 0;
        foreach ($quotation->getItems() as $item) {
            if ($item->getProduct() === null) {
                ++$omittedCount;
                continue;
            }

            $payloadItems[] = [
                'product_id' => $item->getProduct()->getId(),
                'kind' => 'product',
                'description' => $item->getDescription(),
                'qty' => $item->getQty(),
                'unit_price' => $item->getUnitPrice(),
                'iva_rate' => $item->getIvaRate(),
            ];
        }

        $request->getSession()->set('quotation_to_sale_payload', [
            'customer_id' => $quotation->getCustomer()?->getId(),
            'items' => $payloadItems,
        ]);

        if ($omittedCount > 0) {
            $this->addFlash('warning', 'Algunos productos del presupuesto ya no estaban disponibles y no se cargaron en el POS.');
        }

        $this->addFlash('success', 'Presupuesto cargado en POS para continuar la venta.');

        return $this->redirectToRoute('app_sale_new');
    }

    private function requireBusiness(): Business
    {
        return $this->businessContext->requireCurrentBusiness();
    }

    private function denyIfDifferentBusiness(Quotation $quotation, Business $business): void
    {
        if ($quotation->getBusiness() !== $business) {
            throw new AccessDeniedException('Solo podés gestionar presupuestos de tu comercio.');
        }
    }
}
