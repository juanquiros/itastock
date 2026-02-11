<?php

namespace App\Controller;

use App\Entity\Business;
use App\Entity\BusinessArcaConfig;
use App\Entity\Customer;
use App\Entity\ArcaCreditNote;
use App\Entity\ArcaInvoice;
use App\Entity\Payment;
use App\Entity\Product;
use App\Entity\PriceList;
use App\Entity\Sale;
use App\Entity\SaleItem;
use App\Entity\StockMovement;
use App\Entity\User;
use App\Entity\BusinessUser;
use App\Repository\ArcaInvoiceRepository;
use App\Repository\ArcaCreditNoteRepository;
use App\Repository\BusinessArcaConfigRepository;
use App\Repository\BusinessUserRepository;
use App\Repository\CashSessionRepository;
use App\Repository\PlatformSettingsRepository;
use App\Repository\PriceListItemRepository;
use App\Repository\PriceListRepository;
use App\Repository\SaleRepository;
use App\Security\BusinessContext;
use App\Service\CustomerAccountService;
use App\Service\DiscountEngine;
use App\Service\ArcaInvoiceService;
use App\Service\ArcaQrService;
use App\Service\PdfService;
use App\Service\PricingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('BUSINESS_SELLER')]
#[Route('/app/pos', name: 'app_sale_')]
class SaleController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CashSessionRepository $cashSessionRepository,
        private readonly PriceListRepository $priceListRepository,
        private readonly PriceListItemRepository $priceListItemRepository,
        private readonly PlatformSettingsRepository $platformSettingsRepository,
        private readonly PricingService $pricingService,
        private readonly CustomerAccountService $customerAccountService,
        private readonly DiscountEngine $discountEngine,
        private readonly PdfService $pdfService,
        private readonly BusinessContext $businessContext,
        private readonly BusinessUserRepository $businessUserRepository,
        private readonly BusinessArcaConfigRepository $arcaConfigRepository,
        private readonly ArcaInvoiceRepository $arcaInvoiceRepository,
        private readonly ArcaCreditNoteRepository $arcaCreditNoteRepository,
        private readonly ArcaInvoiceService $arcaInvoiceService,
        private readonly ArcaQrService $arcaQrService,
        private readonly MailerInterface $mailer,
    ) {
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $user = $this->requireUser();
        $business = $this->requireBusinessContext();
        $openSession = $this->cashSessionRepository->findOpenForUser($business, $user);

        if ($openSession === null) {
            $this->addFlash('danger', 'Necesitás abrir caja para registrar ventas.');

            return $this->redirectToRoute('app_cash_status');
        }

        $productRepository = $this->entityManager->getRepository(Product::class);
        $customerRepository = $this->entityManager->getRepository(Customer::class);
        $products = $productRepository->findBy(['business' => $business, 'isActive' => true], ['name' => 'ASC']);
        $customers = $customerRepository->searchByBusiness($business, null);
        $priceLists = $this->priceListRepository->findActiveForBusiness($business);
        $priceListPrices = $this->priceListItemRepository->findPricesByBusiness($business);
        $defaultPriceList = $this->priceListRepository->findDefaultActiveForBusiness($business);

        if ($request->isMethod('POST')) {
            return $this->handleSubmission($request, $business, $products, $user);
        }

        $settings = $this->platformSettingsRepository->findOneBy([]);

        return $this->render('sale/new.html.twig', [
            'products' => $products,
            'productPayload' => array_map(fn (Product $product) => [
                'id' => $product->getId(),
                'name' => $product->getName(),
                'barcode' => $product->getBarcode(),
                'sku' => $product->getSku(),
                'price' => (float) $product->getBasePrice(),
                'stock' => (float) $product->getStock(),
                'uomBase' => $product->getUomBase(),
                'allowsFractionalQty' => $product->allowsFractionalQty(),
                'qtyStep' => $product->getQtyStep(),
            ], $products),
            'customersPayload' => array_map(static fn (Customer $customer) => [
                'id' => $customer->getId(),
                'name' => $customer->getName(),
                'document' => $customer->getDocumentNumber(),
                'type' => $customer->getCustomerType(),
                'isActive' => $customer->isActive(),
                'priceList' => $customer->getPriceList()?->getId(),
            ], $customers),
            'priceLists' => array_map(static fn (PriceList $list) => [
                'id' => $list->getId(),
                'name' => $list->getName(),
                'isDefault' => $list->isDefault(),
            ], $priceLists),
            'priceListPrices' => $priceListPrices,
            'defaultPriceListId' => $defaultPriceList?->getId(),
            'barcodeScanSoundPath' => $settings?->getBarcodeScanSoundPath(),
        ]);
    }

    #[Route('/snapshot', name: 'snapshot', methods: ['GET'])]
    public function snapshot(): JsonResponse
    {
        $business = $this->requireBusinessContext();

        $productRepository = $this->entityManager->getRepository(Product::class);
        $customerRepository = $this->entityManager->getRepository(Customer::class);
        $products = $productRepository->findBy(['business' => $business, 'isActive' => true], ['name' => 'ASC']);
        $customers = $customerRepository->searchByBusiness($business, null);
        $priceLists = $this->priceListRepository->findActiveForBusiness($business);
        $priceListPrices = $this->priceListItemRepository->findPricesByBusiness($business);
        $defaultPriceList = $this->priceListRepository->findDefaultActiveForBusiness($business);

        return $this->json([
            'products' => array_map(static fn (Product $product) => [
                'id' => $product->getId(),
                'name' => $product->getName(),
                'barcode' => $product->getBarcode(),
                'sku' => $product->getSku(),
                'price' => (float) $product->getBasePrice(),
                'stock' => (float) $product->getStock(),
                'uomBase' => $product->getUomBase(),
                'allowsFractionalQty' => $product->allowsFractionalQty(),
                'qtyStep' => $product->getQtyStep(),
            ], $products),
            'customers' => array_map(static fn (Customer $customer) => [
                'id' => $customer->getId(),
                'name' => $customer->getName(),
                'document' => $customer->getDocumentNumber(),
                'type' => $customer->getCustomerType(),
                'isActive' => $customer->isActive(),
                'priceList' => $customer->getPriceList()?->getId(),
            ], $customers),
            'priceLists' => array_map(static fn (PriceList $list) => [
                'id' => $list->getId(),
                'name' => $list->getName(),
                'isDefault' => $list->isDefault(),
            ], $priceLists),
            'priceListPrices' => $priceListPrices,
            'defaultPriceListId' => $defaultPriceList?->getId(),
        ]);
    }

    #[Route('/preview', name: 'preview', methods: ['POST'])]
    public function preview(Request $request): JsonResponse
    {
        $business = $this->requireBusinessContext();

        $payload = $request->toArray();
        $itemsData = $payload['items'] ?? [];
        $customerId = (int) ($payload['customer_id'] ?? 0);
        $paymentMethod = strtoupper((string) ($payload['payment_method'] ?? 'CASH'));
        $allowedMethods = ['CASH', 'TRANSFER', 'CARD', 'ACCOUNT'];

        if (!in_array($paymentMethod, $allowedMethods, true)) {
            return $this->json(['error' => 'Medio de pago inválido.'], Response::HTTP_BAD_REQUEST);
        }

        $productRepository = $this->entityManager->getRepository(Product::class);
        $products = $productRepository->findBy(['business' => $business, 'isActive' => true], ['name' => 'ASC']);
        $productIndex = [];
        foreach ($products as $product) {
            $productIndex[$product->getId()] = $product;
        }

        $customer = null;
        if ($customerId > 0) {
            $customer = $this->entityManager->getRepository(Customer::class)->find($customerId);
            if (!$customer instanceof Customer || $customer->getBusiness() !== $business) {
                return $this->json(['error' => 'Cliente inválido.'], Response::HTTP_BAD_REQUEST);
            }

            if (!$customer->isActive() && $paymentMethod === 'ACCOUNT') {
                return $this->json(['error' => 'El cliente está inactivo y no puede usar cuenta corriente.'], Response::HTTP_BAD_REQUEST);
            }
        }

        if ($paymentMethod === 'ACCOUNT' && !$customer instanceof Customer) {
            return $this->json(['error' => 'Elegí un cliente activo para vender en cuenta corriente.'], Response::HTTP_BAD_REQUEST);
        }

        if (!is_array($itemsData) || count($itemsData) === 0) {
            return $this->json([
                'subtotal' => '0.00',
                'discountTotal' => '0.00',
                'total' => '0.00',
                'discounts' => [],
                'lines' => [],
            ]);
        }

        $sale = new Sale();
        $sale->setBusiness($business);
        $sale->setCustomer($customer);

        $subtotalCents = 0;
        foreach ($itemsData as $row) {
            $productId = (int) ($row['product_id'] ?? 0);
            $qty = $this->normalizeQuantity($row['qty'] ?? null);

            if ($productId === 0 || $qty === null || bccomp($qty, '0', 3) <= 0) {
                continue;
            }

            if (!isset($productIndex[$productId])) {
                return $this->json(['error' => 'Producto inválido.'], Response::HTTP_BAD_REQUEST);
            }

            $product = $productIndex[$productId];

            $qtyStep = $product->getQtyStep() ?? '0.100';
            $allowsFractional = $product->allowsFractionalQty();

            if ($product->getUomBase() === Product::UOM_UNIT && $allowsFractional === false && !$this->isIntegerQuantity($qty)) {
                return $this->json(['error' => sprintf('La cantidad de %s debe ser entera.', $product->getName())], Response::HTTP_BAD_REQUEST);
            }

            if ($allowsFractional === false && bccomp($qty, '1', 3) < 0) {
                return $this->json(['error' => sprintf('La cantidad mínima para %s es 1.', $product->getName())], Response::HTTP_BAD_REQUEST);
            }

            if ($allowsFractional && !$this->isMultipleOfStep($qty, $qtyStep)) {
                return $this->json(['error' => sprintf('La cantidad de %s debe ser múltiplo de %s.', $product->getName(), $qtyStep)], Response::HTTP_BAD_REQUEST);
            }

            if (bccomp($qty, $product->getStock(), 3) === 1) {
                return $this->json(['error' => sprintf('No hay stock suficiente para %s.', $product->getName())], Response::HTTP_BAD_REQUEST);
            }

            $unitPrice = $this->pricingService->resolveUnitPrice($product, $customer);
            [$lineTotal, $lineCents] = $this->calculateLineTotal(number_format($unitPrice, 2, '.', ''), $qty);
            $subtotalCents += $lineCents;

            $item = new SaleItem();
            $item->setProduct($product);
            $item->setDescription($product->getName());
            $item->setQty($qty);
            $item->setUnitPrice(number_format($unitPrice, 2, '.', ''));
            $item->setLineSubtotal($lineTotal);
            $item->setLineDiscount('0.00');
            $item->setLineTotal($lineTotal);
            $sale->addItem($item);
        }

        if (count($sale->getItems()) === 0) {
            return $this->json([
                'subtotal' => '0.00',
                'discountTotal' => '0.00',
                'total' => '0.00',
                'discounts' => [],
                'lines' => [],
            ]);
        }

        $sale->setSubtotal($this->formatCents($subtotalCents));
        $sale->setDiscountTotal('0.00');
        $sale->setTotal($this->formatCents($subtotalCents));

        $this->discountEngine->applyDiscounts($sale, $paymentMethod);

        return $this->json([
            'subtotal' => $sale->getSubtotal(),
            'discountTotal' => $sale->getDiscountTotal(),
            'total' => $sale->getTotal(),
            'discounts' => array_map(static fn ($saleDiscount) => [
                'name' => $saleDiscount->getDiscountName(),
                'actionType' => $saleDiscount->getActionType(),
                'actionValue' => $saleDiscount->getActionValue(),
                'appliedAmount' => $saleDiscount->getAppliedAmount(),
            ], $sale->getSaleDiscounts()->toArray()),
            'lines' => array_map(static fn (SaleItem $item) => [
                'product_id' => $item->getProduct()?->getId(),
                'lineSubtotal' => $item->getLineSubtotal(),
                'lineDiscount' => $item->getLineDiscount(),
                'lineTotal' => $item->getLineTotal(),
            ], $sale->getItems()->toArray()),
        ]);
    }

    #[Route('/{id}/ticket', name: 'ticket', methods: ['GET'])]
    public function ticket(Sale $sale): Response
    {
        $user = $this->requireUser();
        $business = $this->requireBusinessContext();

        if ($sale->getBusiness() !== $business) {
            throw new AccessDeniedException('Solo podés ver tickets de tu comercio.');
        }

        $membership = $this->businessUserRepository->findActiveMembership($user, $business);
        $arcaConfig = $this->arcaConfigRepository->findOneBy(['business' => $business]);
        $arcaInvoice = $this->arcaInvoiceRepository->findOneBy([
            'business' => $business,
            'sale' => $sale,
        ]);
        $arcaCreditNote = $this->arcaCreditNoteRepository->findOneBy([
            'business' => $business,
            'sale' => $sale,
        ]);

        $canInvoice = $sale->getStatus() === Sale::STATUS_CONFIRMED
            && $arcaConfig?->isArcaEnabled()
            && $membership?->isArcaEnabledForThisCashier()
            && $membership?->getArcaMode() === 'INVOICE'
            && $membership?->getArcaPosNumber() !== null
            && !$arcaInvoice;

        $canCreditNote = $sale->getStatus() === Sale::STATUS_CONFIRMED
            && $arcaInvoice?->getStatus() === \App\Entity\ArcaInvoice::STATUS_AUTHORIZED
            && $arcaConfig?->isArcaEnabled()
            && $membership?->isArcaEnabledForThisCashier()
            && $membership?->getArcaMode() === 'INVOICE'
            && $membership?->getArcaPosNumber() !== null
            && !$arcaCreditNote;

        $isAutoInvoicing = (bool) ($membership?->getArcaMode() === 'INVOICE' && $membership?->isArcaAutoIssueInvoice());
        $showInvoiceAsTicket = (bool) (
            $membership?->getArcaMode() === 'INVOICE'
            && $membership?->isArcaAutoIssueInvoice()
            && $arcaInvoice
            && $arcaInvoice->getStatus() === ArcaInvoice::STATUS_AUTHORIZED
        );

        $customers = [];
        if ($canInvoice && $sale->getCustomer() === null) {
            $customerRepository = $this->entityManager->getRepository(Customer::class);
            $customers = array_slice($customerRepository->findActiveForBusiness($business), 0, 200);
        }

        return $this->render('sale/ticket.html.twig', [
            'sale' => $sale,
            'remitoNumber' => $this->formatRemitoNumber($sale),
            'arcaInvoice' => $arcaInvoice,
            'arcaCreditNote' => $arcaCreditNote,
            'canInvoice' => $canInvoice,
            'canCreditNote' => $canCreditNote,
            'isAutoInvoicing' => $isAutoInvoicing,
            'showInvoiceAsTicket' => $showInvoiceAsTicket,
            'ticketPaperSize' => $user->getTicketPaperSize(),
            'ticketQrDataUri' => $showInvoiceAsTicket ? $this->buildInvoiceQrDataUri($arcaInvoice, $business->getArcaConfig()) : null,
            'ticketQrUrl' => $showInvoiceAsTicket ? $this->buildInvoiceQrUrl($arcaInvoice, $business->getArcaConfig()) : null,
            'customers' => $customers,
        ]);
    }



    #[Route('/{id}/ticket/send-email', name: 'ticket_send_email', methods: ['POST'])]
    public function sendTicketByEmail(Request $request, Sale $sale): Response
    {
        $user = $this->requireUser();
        $business = $this->requireBusinessContext();

        if ($sale->getBusiness() !== $business) {
            throw new AccessDeniedException('Solo podés enviar tickets de tu comercio.');
        }

        if (!$this->isCsrfTokenValid('ticket_send_email_'.$sale->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'No se pudo validar la solicitud de envío.');

            return $this->redirectToRoute('app_sale_ticket', ['id' => $sale->getId()]);
        }

        $customer = $sale->getCustomer();
        $recipient = $customer?->getEmail();

        if (!$customer || !$recipient) {
            $this->addFlash('danger', 'La venta no tiene un cliente con email registrado.');

            return $this->redirectToRoute('app_sale_ticket', ['id' => $sale->getId()]);
        }

        $membership = $this->businessUserRepository->findActiveMembership($user, $business);
        $arcaInvoice = $this->arcaInvoiceRepository->findOneBy([
            'business' => $business,
            'sale' => $sale,
        ]);

        $showInvoiceAsTicket = (bool) (
            $membership?->getArcaMode() === 'INVOICE'
            && $membership?->isArcaAutoIssueInvoice()
            && $arcaInvoice
            && $arcaInvoice->getStatus() === ArcaInvoice::STATUS_AUTHORIZED
        );

        $generatedAt = new \DateTimeImmutable();

        if ($showInvoiceAsTicket) {
            $filename = sprintf('factura-venta-%d.pdf', $sale->getId());
            $bytes = $this->pdfService->generateBytes('arca/invoice_pdf.html.twig', [
                'invoice' => $arcaInvoice,
                'business' => $business,
                'sale' => $sale,
                'generatedAt' => $generatedAt,
                'qrDataUri' => $this->buildInvoiceQrDataUri($arcaInvoice, $business->getArcaConfig()),
                'qrUrl' => $this->buildInvoiceQrUrl($arcaInvoice, $business->getArcaConfig()),
            ]);
            $subjectDoc = 'Factura';
        } else {
            $filename = sprintf('remito-venta-%d.pdf', $sale->getId());
            $bytes = $this->pdfService->generateBytes('sale/ticket_pdf.html.twig', [
                'business' => $business,
                'sale' => $sale,
                'remitoNumber' => $this->formatRemitoNumber($sale),
                'generatedAt' => $generatedAt,
            ]);
            $subjectDoc = 'Remito';
        }

        $email = (new Email())
            ->to($recipient)
            ->subject(sprintf('%s venta #%d - %s', $subjectDoc, $sale->getId(), $business->getName()))
            ->text(sprintf('Hola %s, te compartimos el %s de tu compra en %s.', $customer->getName(), strtolower($subjectDoc), $business->getName()))
            ->attach($bytes, $filename, 'application/pdf');

        $this->mailer->send($email);

        $this->addFlash('success', sprintf('Comprobante enviado por email a %s.', $recipient));

        return $this->redirectToRoute('app_sale_ticket', ['id' => $sale->getId()]);
    }

    #[Route('/{id}/ticket/pdf', name: 'ticket_pdf', methods: ['GET'])]
    public function ticketPdf(Sale $sale): Response
    {
        $user = $this->requireUser();
        $business = $this->requireBusinessContext();

        if ($sale->getBusiness() !== $business) {
            throw new AccessDeniedException('Solo podés ver tickets de tu comercio.');
        }

        $membership = $this->businessUserRepository->findActiveMembership($user, $business);
        $arcaInvoice = $this->arcaInvoiceRepository->findOneBy([
            'business' => $business,
            'sale' => $sale,
        ]);

        $showInvoiceAsTicket = (bool) (
            $membership?->getArcaMode() === 'INVOICE'
            && $membership?->isArcaAutoIssueInvoice()
            && $arcaInvoice
            && $arcaInvoice->getStatus() === ArcaInvoice::STATUS_AUTHORIZED
        );

        if ($showInvoiceAsTicket) {
            return $this->pdfService->render('arca/invoice_pdf.html.twig', [
                'invoice' => $arcaInvoice,
                'business' => $business,
                'sale' => $sale,
                'generatedAt' => new \DateTimeImmutable(),
                'qrDataUri' => $this->buildInvoiceQrDataUri($arcaInvoice, $business->getArcaConfig()),
            ], sprintf('factura-venta-%d.pdf', $sale->getId()));
        }

        return $this->pdfService->render('sale/ticket_pdf.html.twig', [
            'business' => $business,
            'sale' => $sale,
            'remitoNumber' => $this->formatRemitoNumber($sale),
            'generatedAt' => new \DateTimeImmutable(),
        ], sprintf('remito-venta-%d.pdf', $sale->getId()));
    }

    #[Route('/{id}/arca/pdf', name: 'arca_pdf', methods: ['GET'])]
    public function arcaPdf(Sale $sale): Response
    {
        $business = $this->requireBusinessContext();

        if ($sale->getBusiness() !== $business) {
            throw new AccessDeniedException('Solo podés ver facturas de tu comercio.');
        }

        $invoice = $this->arcaInvoiceRepository->findOneBy([
            'business' => $business,
            'sale' => $sale,
        ]);

        if (!$invoice || $invoice->getStatus() !== \App\Entity\ArcaInvoice::STATUS_AUTHORIZED) {
            throw $this->createNotFoundException('Factura no disponible.');
        }

        return $this->pdfService->render('arca/invoice_pdf.html.twig', [
            'invoice' => $invoice,
            'business' => $business,
            'sale' => $sale,
            'generatedAt' => new \DateTimeImmutable(),
            'qrDataUri' => $this->buildInvoiceQrDataUri($invoice, $business->getArcaConfig()),
            'qrUrl' => $this->buildInvoiceQrUrl($invoice, $business->getArcaConfig()),
        ], sprintf('factura-venta-%d.pdf', $sale->getId()));
    }

    #[Route('/{id}/arca/credit-note/pdf', name: 'arca_credit_note_pdf', methods: ['GET'])]
    public function arcaCreditNotePdf(Sale $sale): Response
    {
        $business = $this->requireBusinessContext();

        if ($sale->getBusiness() !== $business) {
            throw new AccessDeniedException('Solo podés ver notas de crédito de tu comercio.');
        }

        $creditNote = $this->arcaCreditNoteRepository->findOneBy([
            'business' => $business,
            'sale' => $sale,
        ]);

        if (!$creditNote || $creditNote->getStatus() !== \App\Entity\ArcaCreditNote::STATUS_AUTHORIZED) {
            throw $this->createNotFoundException('Nota de crédito no disponible.');
        }

        return $this->pdfService->render('arca/credit_note_pdf.html.twig', [
            'creditNote' => $creditNote,
            'business' => $business,
            'sale' => $sale,
            'generatedAt' => new \DateTimeImmutable(),
            'qrDataUri' => $this->buildCreditNoteQrDataUri($creditNote, $business->getArcaConfig()),
            'qrUrl' => $this->buildCreditNoteQrUrl($creditNote, $business->getArcaConfig()),
        ], sprintf('nota-credito-venta-%d.pdf', $sale->getId()));
    }

    #[Route('/{id}/arca/issue', name: 'arca_issue', methods: ['POST'])]
    public function issueArcaInvoice(Request $request, Sale $sale): Response
    {
        $user = $this->requireUser();
        $business = $this->requireBusinessContext();

        if ($sale->getBusiness() !== $business) {
            throw new AccessDeniedException('Solo podés facturar ventas de tu comercio.');
        }

        if (!$this->isCsrfTokenValid('arca_invoice_'.$sale->getId(), (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('app_sale_ticket', ['id' => $sale->getId()]);
        }

        if ($sale->getStatus() !== Sale::STATUS_CONFIRMED) {
            $this->addFlash('danger', 'Solo podés facturar ventas confirmadas.');

            return $this->redirectToRoute('app_sale_ticket', ['id' => $sale->getId()]);
        }

        $arcaConfig = $this->arcaConfigRepository->findOneBy(['business' => $business]);
        $membership = $this->businessUserRepository->findActiveMembership($user, $business);

        if (!$arcaConfig || !$arcaConfig->isArcaEnabled()) {
            $this->addFlash('danger', 'ARCA no está habilitado para este comercio.');

            return $this->redirectToRoute('app_sale_ticket', ['id' => $sale->getId()]);
        }

        if (!$membership || !$membership->isArcaEnabledForThisCashier() || $membership->getArcaMode() !== 'INVOICE') {
            $this->addFlash('danger', 'Tu caja no está habilitada para facturar.');

            return $this->redirectToRoute('app_sale_ticket', ['id' => $sale->getId()]);
        }

        if ($membership->getArcaPosNumber() === null) {
            $this->addFlash('danger', 'Necesitás configurar el punto de venta ARCA para tu usuario.');

            return $this->redirectToRoute('app_sale_ticket', ['id' => $sale->getId()]);
        }

        $existing = $this->arcaInvoiceRepository->findOneBy(['business' => $business, 'sale' => $sale]);
        if ($existing) {
            $this->addFlash('warning', 'Esta venta ya tiene una factura asociada.');

            return $this->redirectToRoute('app_sale_ticket', ['id' => $sale->getId()]);
        }

        $priceMode = (string) $request->request->get('price_mode', ArcaInvoiceService::PRICE_MODE_HISTORIC);
        if (!in_array($priceMode, [ArcaInvoiceService::PRICE_MODE_HISTORIC, ArcaInvoiceService::PRICE_MODE_CURRENT], true)) {
            $priceMode = ArcaInvoiceService::PRICE_MODE_HISTORIC;
        }

        $receiverMode = (string) $request->request->get('receiver_mode', 'final');
        $receiverCustomer = null;
        $receiverIvaConditionId = null;
        $saleCustomer = $sale->getCustomer();

        if ($saleCustomer) {
            $receiverMode = 'customer';
            $receiverCustomer = $saleCustomer;
            $receiverIvaConditionId = $saleCustomer->getIvaConditionId() ?? $arcaConfig->getDefaultReceiverIvaConditionId();
        } elseif ($receiverMode === 'customer') {
            $customerId = $request->request->getInt('customer_id');
            $customer = $customerId > 0 ? $this->entityManager->getRepository(Customer::class)->find($customerId) : null;
            if (!$customer instanceof Customer || $customer->getBusiness() !== $business) {
                $this->addFlash('danger', 'Seleccioná un cliente válido para facturar.');

                return $this->redirectToRoute('app_sale_ticket', ['id' => $sale->getId()]);
            }

            if ($customer->getIvaConditionId() === null) {
                $this->addFlash('danger', 'El cliente no tiene condición IVA configurada.');

                return $this->redirectToRoute('app_sale_ticket', ['id' => $sale->getId()]);
            }

            $receiverCustomer = $customer;
            $receiverIvaConditionId = $customer->getIvaConditionId();
        } else {
            $receiverMode = 'final';
            $receiverIvaConditionId = $arcaConfig->getDefaultReceiverIvaConditionId();
        }

        if ($receiverIvaConditionId === null) {
            $this->addFlash('danger', 'Configurá la Condición IVA del receptor en ARCA o asignala al cliente.');

            return $this->redirectToRoute('app_sale_ticket', ['id' => $sale->getId()]);
        }

        $invoice = $this->arcaInvoiceService->buildInvoiceFromSale($sale, $user, $membership, $arcaConfig, $priceMode);
        $invoice->setReceiverMode($receiverMode);
        $invoice->setReceiverCustomer($receiverCustomer);
        $invoice->setReceiverIvaConditionId($receiverIvaConditionId);
        $this->entityManager->flush();

        $this->arcaInvoiceService->requestCae($invoice, $arcaConfig);

        if ($invoice->getStatus() === \App\Entity\ArcaInvoice::STATUS_AUTHORIZED) {
            $this->addFlash('success', 'Factura autorizada correctamente.');
        } else {
            $this->addFlash('danger', 'No se pudo autorizar la factura. Revisá el detalle en reportes.');
        }

        return $this->redirectToRoute('app_sale_ticket', ['id' => $sale->getId()]);
    }

    private function handleSubmission(Request $request, Business $business, array $products, User $user): Response
    {
        $itemsData = $request->request->all('items');
        $customerId = (int) $request->request->get('customer_id', 0);
        $paymentMethod = strtoupper((string) $request->request->get('payment_method', 'CASH'));
        $allowedMethods = ['CASH', 'TRANSFER', 'CARD', 'ACCOUNT'];

        if (!in_array($paymentMethod, $allowedMethods, true)) {
            $this->addFlash('danger', 'Seleccioná un medio de pago válido.');

            return $this->redirectToRoute('app_sale_new');
        }

        if (!is_array($itemsData) || count($itemsData) === 0) {
            $this->addFlash('danger', 'Agregá al menos un producto para registrar la venta.');

            return $this->redirectToRoute('app_sale_new');
        }

        $productIndex = [];
        foreach ($products as $product) {
            $productIndex[$product->getId()] = $product;
        }

        $customer = null;
        if ($customerId > 0) {
            $customer = $this->entityManager->getRepository(Customer::class)->find($customerId);

            if (!$customer instanceof Customer || $customer->getBusiness() !== $business) {
                $this->addFlash('danger', 'El cliente seleccionado no pertenece a tu comercio.');

                return $this->redirectToRoute('app_sale_new');
            }

            if (!$customer->isActive() && $paymentMethod === 'ACCOUNT') {
                $this->addFlash('danger', 'El cliente está inactivo y no puede usar cuenta corriente.');

                return $this->redirectToRoute('app_sale_new');
            }
        }

        if ($paymentMethod === 'ACCOUNT' && !$customer instanceof Customer) {
            $this->addFlash('danger', 'Elegí un cliente activo para vender en cuenta corriente.');

            return $this->redirectToRoute('app_sale_new');
        }

        $sale = new Sale();
        $sale->setBusiness($business);
        $sale->setCreatedBy($user);
        $sale->setCustomer($customer);
        $posNumber = $this->resolvePosNumber($user);
        $sale->setPosNumber($posNumber);
        $sale->setPosSequence($this->nextPosSequence($business, $posNumber));

        $subtotalCents = 0;

        foreach ($itemsData as $row) {
            $productId = (int) ($row['product_id'] ?? 0);
            $qty = $this->normalizeQuantity($row['qty'] ?? null);

            if ($productId === 0 || $qty === null || bccomp($qty, '0', 3) <= 0) {
                continue;
            }

            if (!isset($productIndex[$productId])) {
                throw new AccessDeniedException('El producto no pertenece a tu comercio.');
            }

            $product = $productIndex[$productId];

            $qtyStep = $product->getQtyStep() ?? '0.100';
            $allowsFractional = $product->allowsFractionalQty();

            if ($product->getUomBase() === Product::UOM_UNIT && $allowsFractional === false && !$this->isIntegerQuantity($qty)) {
                $this->addFlash('danger', sprintf('La cantidad de %s debe ser entera.', $product->getName()));

                return $this->redirectToRoute('app_sale_new');
            }

            if ($allowsFractional === false && bccomp($qty, '1', 3) < 0) {
                $this->addFlash('danger', sprintf('La cantidad mínima para %s es 1.', $product->getName()));

                return $this->redirectToRoute('app_sale_new');
            }

            if ($allowsFractional && !$this->isMultipleOfStep($qty, $qtyStep)) {
                $this->addFlash('danger', sprintf('La cantidad de %s debe ser múltiplo de %s.', $product->getName(), $qtyStep));

                return $this->redirectToRoute('app_sale_new');
            }

            if (bccomp($qty, $product->getStock(), 3) === 1) {
                $this->addFlash('danger', sprintf('No hay stock suficiente para %s.', $product->getName()));

                return $this->redirectToRoute('app_sale_new');
            }

            $unitPrice = $this->pricingService->resolveUnitPrice($product, $customer);
            [$lineTotal, $lineCents] = $this->calculateLineTotal(number_format($unitPrice, 2, '.', ''), $qty);
            $subtotalCents += $lineCents;

            $item = new SaleItem();
            $item->setProduct($product);
            $item->setDescription($product->getName());
            $item->setQty($qty);
            $item->setUnitPrice(number_format($unitPrice, 2, '.', ''));
            $item->setLineSubtotal($lineTotal);
            $item->setLineDiscount('0.00');
            $item->setLineTotal($lineTotal);
            $sale->addItem($item);

            $product->adjustStock(bcsub('0', $qty, 3));

            $movement = new StockMovement();
            $movement->setProduct($product);
            $movement->setType(StockMovement::TYPE_SALE);
            $movement->setQty(bcsub('0', $qty, 3));
            $movement->setReference('Venta');
            $movement->setCreatedBy($user);

            $this->entityManager->persist($movement);
        }

        if (count($sale->getItems()) === 0) {
            $this->addFlash('danger', 'Agregá al menos un producto válido para registrar la venta.');

            return $this->redirectToRoute('app_sale_new');
        }

        $sale->setSubtotal($this->formatCents($subtotalCents));
        $sale->setDiscountTotal('0.00');
        $sale->setTotal($this->formatCents($subtotalCents));

        $this->discountEngine->applyDiscounts($sale, $paymentMethod);

        $payment = new Payment();
        $payment->setAmount($sale->getTotal());
        $payment->setMethod($paymentMethod);
        $payment->setReferenceType(Payment::REFERENCE_SALE);
        $sale->addPayment($payment);

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $this->entityManager->persist($sale);
            $this->entityManager->flush();

            if ($paymentMethod === 'ACCOUNT') {
                $this->customerAccountService->addDebitForSale($sale);
                $this->entityManager->flush();
            }

            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();

            throw $exception;
        }

        $membership = $this->businessUserRepository->findActiveMembership($user, $business);
        $arcaConfig = $this->arcaConfigRepository->findOneBy(['business' => $business]);
        $arcaInvoice = $this->arcaInvoiceRepository->findOneBy([
            'business' => $business,
            'sale' => $sale,
        ]);

        $shouldAutoInvoice = $sale->getStatus() === Sale::STATUS_CONFIRMED
            && $arcaConfig?->isArcaEnabled()
            && $membership?->isArcaEnabledForThisCashier()
            && $membership?->getArcaMode() === 'INVOICE'
            && $membership?->getArcaPosNumber() !== null
            && $membership?->isArcaAutoIssueInvoice()
            && !$arcaInvoice;

        if ($shouldAutoInvoice) {
            $autoInvoice = null;

            try {
                $autoInvoice = $this->arcaInvoiceService->buildInvoiceFromSale(
                    $sale,
                    $user,
                    $membership,
                    $arcaConfig,
                    ArcaInvoiceService::PRICE_MODE_HISTORIC
                );

                $receiverMode = 'final';
                $receiverCustomer = null;
                $receiverIvaConditionId = $arcaConfig?->getDefaultReceiverIvaConditionId();
                $saleCustomer = $sale->getCustomer();

                if ($saleCustomer) {
                    $receiverMode = 'customer';
                    $receiverCustomer = $saleCustomer;
                    $receiverIvaConditionId = $saleCustomer->getIvaConditionId() ?? $receiverIvaConditionId;
                }

                if ($receiverIvaConditionId === null) {
                    throw new \RuntimeException('Condición IVA del receptor no configurada.');
                }

                $autoInvoice->setReceiverMode($receiverMode);
                $autoInvoice->setReceiverCustomer($receiverCustomer);
                $autoInvoice->setReceiverIvaConditionId($receiverIvaConditionId);
                $this->entityManager->flush();

                $this->arcaInvoiceService->requestCae($autoInvoice, $arcaConfig);

                if ($autoInvoice->getStatus() === \App\Entity\ArcaInvoice::STATUS_AUTHORIZED) {
                    $this->addFlash('success', 'Factura emitida automáticamente.');
                } else {
                    $this->addFlash('warning', 'Venta registrada, pero no se pudo emitir factura ARCA. Revisá el ticket.');
                }
            } catch (\Throwable $exception) {
                if ($autoInvoice) {
                    $autoInvoice->setStatus(\App\Entity\ArcaInvoice::STATUS_REJECTED);
                    $autoInvoice->setArcaRawResponse([
                        'error' => $exception->getMessage(),
                    ]);
                    $this->entityManager->flush();
                }

                $this->addFlash('warning', 'Venta registrada, pero no se pudo emitir factura ARCA. Revisá el ticket.');
            }
        }

        $this->addFlash('success', 'Venta registrada.');

        return $this->redirectToRoute('app_sale_ticket', ['id' => $sale->getId()]);
    }

    private function normalizeQuantity(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $stringValue = is_string($value) ? trim($value) : (string) $value;
        $stringValue = str_replace(',', '.', $stringValue);

        if ($stringValue === '') {
            return null;
        }

        if (!preg_match('/^\d+(?:\.\d{1,3})?$/', $stringValue)) {
            return null;
        }

        return bcadd($stringValue, '0', 3);
    }

    private function isIntegerQuantity(string $qty): bool
    {
        $normalized = bcadd($qty, '0', 3);

        if (!str_contains($normalized, '.')) {
            return true;
        }

        [, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');

        return (int) rtrim($fraction, '0') === 0;
    }

    private function isMultipleOfStep(string $qty, string $step): bool
    {
        if (bccomp($step, '0', 3) <= 0) {
            return false;
        }

        $qtyInt = $this->decimalToIntScale($qty, 3);
        $stepInt = $this->decimalToIntScale($step, 3);

        if ($stepInt === 0) {
            return false;
        }

        return $qtyInt % $stepInt === 0;
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function calculateLineTotal(string $unitPrice, string $qty): array
    {
        $unitCents = $this->decimalToIntScale($unitPrice, 2);
        $qtyScaled = $this->decimalToIntScale($qty, 3);

        $raw = $unitCents * $qtyScaled;
        $lineCents = intdiv($raw, 1000);
        $remainder = abs($raw % 1000);

        if ($remainder >= 500) {
            $lineCents += $raw >= 0 ? 1 : -1;
        }

        return [$this->formatCents($lineCents), $lineCents];
    }

    private function formatCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $absolute = abs($cents);
        $major = intdiv($absolute, 100);
        $minor = $absolute % 100;

        return sprintf('%s%d.%02d', $sign, $major, $minor);
    }

    private function decimalToIntScale(string $value, int $scale): int
    {
        $normalized = bcadd($value, '0', $scale);
        $sign = 1;

        if (str_starts_with($normalized, '-')) {
            $sign = -1;
            $normalized = substr($normalized, 1);
        }

        [$integer, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');
        $fraction = str_pad($fraction, $scale, '0');

        $intString = ltrim($integer.$fraction, '0');
        if ($intString === '') {
            $intString = '0';
        }

        return $sign * (int) $intString;
    }

    private function resolvePosNumber(User $user): int
    {
        if ($user->getPosNumber() !== null) {
            return $user->getPosNumber();
        }

        $membership = $this->businessContext->getUserMembershipForCurrentBusiness($user);
        if ($membership && in_array($membership->getRole(), [BusinessUser::ROLE_OWNER, BusinessUser::ROLE_ADMIN], true)) {
            return 1;
        }

        return $user->getId() ?? 1;
    }

    private function nextPosSequence(Business $business, int $posNumber): int
    {
        /** @var SaleRepository $saleRepository */
        $saleRepository = $this->entityManager->getRepository(Sale::class);

        return $saleRepository->nextPosSequence($business, $posNumber);
    }

    private function formatRemitoNumber(Sale $sale): string
    {
        $posNumber = $sale->getPosNumber();
        $sequence = $sale->getPosSequence();

        if ($posNumber === null) {
            $creator = $sale->getCreatedBy();
            if ($creator instanceof User) {
                $posNumber = $this->resolvePosNumber($creator);
            } else {
                $posNumber = 1;
            }
        }

        if ($sequence === null) {
            $sequence = $sale->getId() ?? 0;
        }

        return sprintf('R%05d-%08d', $posNumber, $sequence);
    }

    private function requireBusinessContext(): Business
    {
        return $this->businessContext->requireCurrentBusiness();
    }


    private function buildInvoiceQrDataUri(ArcaInvoice $invoice, ?BusinessArcaConfig $config): ?string
    {
        $payload = $this->arcaQrService->buildPayloadForInvoice($invoice, $config);
        if ($payload === []) {
            return null;
        }

        return $this->arcaQrService->generatePngDataUri($this->arcaQrService->buildQrUrl($payload));
    }

    private function buildInvoiceQrUrl(ArcaInvoice $invoice, ?BusinessArcaConfig $config): ?string
    {
        $payload = $this->arcaQrService->buildPayloadForInvoice($invoice, $config);
        if ($payload === []) {
            return null;
        }

        return $this->arcaQrService->buildQrUrl($payload);
    }

    private function buildCreditNoteQrDataUri(ArcaCreditNote $creditNote, ?BusinessArcaConfig $config): ?string
    {
        $payload = $this->arcaQrService->buildPayloadForCreditNote($creditNote, $config);
        if ($payload === []) {
            return null;
        }

        return $this->arcaQrService->generatePngDataUri($this->arcaQrService->buildQrUrl($payload));
    }

    private function buildCreditNoteQrUrl(ArcaCreditNote $creditNote, ?BusinessArcaConfig $config): ?string
    {
        $payload = $this->arcaQrService->buildPayloadForCreditNote($creditNote, $config);
        if ($payload === []) {
            return null;
        }

        return $this->arcaQrService->buildQrUrl($payload);
    }

    private function requireUser(): User
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw new AccessDeniedException('Debés iniciar sesión para operar.');
        }

        return $user;
    }
}
