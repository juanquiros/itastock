<?php

namespace App\Controller;

use App\Entity\Business;
use App\Entity\Customer;
use App\Entity\Payment;
use App\Entity\Product;
use App\Entity\PriceList;
use App\Entity\Sale;
use App\Entity\SaleItem;
use App\Entity\StockMovement;
use App\Entity\User;
use App\Repository\CashSessionRepository;
use App\Repository\PriceListItemRepository;
use App\Repository\PriceListRepository;
use App\Service\CustomerAccountService;
use App\Service\PricingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/app/pos', name: 'app_sale_')]
class SaleController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CashSessionRepository $cashSessionRepository,
        private readonly PriceListRepository $priceListRepository,
        private readonly PriceListItemRepository $priceListItemRepository,
        private readonly PricingService $pricingService,
        private readonly CustomerAccountService $customerAccountService,
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
        $customers = $customerRepository->findActiveForBusiness($business);
        $priceLists = $this->priceListRepository->findActiveForBusiness($business);
        $priceListPrices = $this->priceListItemRepository->findPricesByBusiness($business);
        $defaultPriceList = $this->priceListRepository->findDefaultActiveForBusiness($business);

        if ($request->isMethod('POST')) {
            return $this->handleSubmission($request, $business, $products, $user);
        }

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
                'priceList' => $customer->getPriceList()?->getId(),
            ], $customers),
            'priceLists' => array_map(static fn (PriceList $list) => [
                'id' => $list->getId(),
                'name' => $list->getName(),
                'isDefault' => $list->isDefault(),
            ], $priceLists),
            'priceListPrices' => $priceListPrices,
            'defaultPriceListId' => $defaultPriceList?->getId(),
            'barcodeScanSoundPath' => $business->getBarcodeScanSoundPath(),
        ]);
    }

    #[Route('/{id}/ticket', name: 'ticket', methods: ['GET'])]
    public function ticket(Sale $sale): Response
    {
        $business = $this->requireBusinessContext();

        if ($sale->getBusiness() !== $business) {
            throw new AccessDeniedException('Solo podés ver tickets de tu comercio.');
        }

        return $this->render('sale/ticket.html.twig', [
            'sale' => $sale,
        ]);
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

            if (!$customer->isActive()) {
                $this->addFlash('danger', 'No podés usar un cliente inactivo.');

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

        $totalCents = 0;

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
            $totalCents += $lineCents;

            $item = new SaleItem();
            $item->setProduct($product);
            $item->setDescription($product->getName());
            $item->setQty($qty);
            $item->setUnitPrice(number_format($unitPrice, 2, '.', ''));
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

        $sale->setTotal($this->formatCents($totalCents));

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

    private function requireBusinessContext(): Business
    {
        $business = $this->requireUser()->getBusiness();

        if (!$business instanceof Business) {
            throw new AccessDeniedException('No se puede operar sin un comercio asignado.');
        }

        return $business;
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
