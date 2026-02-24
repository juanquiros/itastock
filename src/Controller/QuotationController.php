<?php

namespace App\Controller;

use App\Entity\Business;
use App\Entity\Quotation;
use App\Entity\QuotationItem;
use App\Repository\QuotationRepository;
use App\Security\BusinessContext;
use App\Service\PdfService;
use App\Service\PricingService;
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
        $business = $this->requireBusinessContext();
        $q = trim((string) $request->query->get('q', ''));
        $page = max(1, (int) $request->query->get('page', 1));
        $pageSize = 20;

        $result = $this->quotationRepository->findForBusinessPaginated($business, $q, $page, $pageSize);
        $total = $result['total'];
        $pages = max(1, (int) ceil($total / $pageSize));

        return $this->render('quotation/index.html.twig', [
            'quotations' => $result['items'],
            'query' => $q,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
        ]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(Quotation $quotation): Response
    {
        $business = $this->requireBusinessContext();
        $this->denyDifferentBusiness($quotation, $business);

        $diffs = [];
        foreach ($quotation->getItems() as $item) {
            $diffs[$item->getId() ?? spl_object_id($item)] = $this->buildDiffFlags($quotation, $item);
        }

        return $this->render('quotation/show.html.twig', [
            'quotation' => $quotation,
            'diffs' => $diffs,
        ]);
    }

    #[Route('/{id}/pdf', name: 'pdf', methods: ['GET'])]
    public function pdf(Quotation $quotation): Response
    {
        $business = $this->requireBusinessContext();
        $this->denyDifferentBusiness($quotation, $business);

        return $this->pdfService->render('quotation/quotation_pdf.html.twig', [
            'quotation' => $quotation,
            'business' => $business,
            'generatedAt' => new \DateTimeImmutable(),
        ], sprintf('presupuesto-%d.pdf', $quotation->getId()));
    }

    /** @return array{stockInsufficient: bool, priceUpdated: bool, inactive: bool, currentPrice: ?string} */
    private function buildDiffFlags(Quotation $quotation, QuotationItem $item): array
    {
        $product = $item->getProduct();
        if ($product === null) {
            return [
                'stockInsufficient' => false,
                'priceUpdated' => false,
                'inactive' => false,
                'currentPrice' => null,
            ];
        }

        $stockInsufficient = bccomp($product->getStock(), $item->getQty(), 3) < 0;
        $current = number_format($this->pricingService->resolveUnitPrice($product, $quotation->getCustomer()), 2, '.', '');
        $priceUpdated = bccomp($current, $item->getUnitPrice(), 2) !== 0;

        return [
            'stockInsufficient' => $stockInsufficient,
            'priceUpdated' => $priceUpdated,
            'inactive' => !$product->isActive(),
            'currentPrice' => $current,
        ];
    }

    private function requireBusinessContext(): Business
    {
        return $this->businessContext->requireCurrentBusiness();
    }

    private function denyDifferentBusiness(Quotation $quotation, Business $business): void
    {
        if ($quotation->getBusiness() !== $business) {
            throw new AccessDeniedException('Solo podés ver presupuestos de tu comercio.');
        }
    }
}
