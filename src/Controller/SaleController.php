<?php

namespace App\Controller;

use App\Entity\Business;
use App\Entity\Customer;
use App\Entity\Payment;
use App\Entity\Product;
use App\Entity\Sale;
use App\Entity\SaleItem;
use App\Entity\StockMovement;
use App\Entity\User;
use App\Repository\CashSessionRepository;
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
                'stock' => $product->getStock(),
            ], $products),
            'customersPayload' => array_map(static fn (Customer $customer) => [
                'id' => $customer->getId(),
                'name' => $customer->getName(),
                'document' => $customer->getDocumentNumber(),
                'type' => $customer->getCustomerType(),
            ], $customers),
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

        $sale = new Sale();
        $sale->setBusiness($business);
        $sale->setCreatedBy($user);
        $sale->setCustomer($customer);

        $total = 0.0;

        foreach ($itemsData as $row) {
            $productId = (int) ($row['product_id'] ?? 0);
            $qty = (int) ($row['qty'] ?? 0);

            if ($productId === 0 || $qty <= 0) {
                continue;
            }

            if (!isset($productIndex[$productId])) {
                throw new AccessDeniedException('El producto no pertenece a tu comercio.');
            }

            $product = $productIndex[$productId];

            if ($qty > $product->getStock()) {
                $this->addFlash('danger', sprintf('No hay stock suficiente para %s.', $product->getName()));

                return $this->redirectToRoute('app_sale_new');
            }

            $unitPrice = (float) $product->getBasePrice();
            $lineTotal = round($unitPrice * $qty, 2);
            $total += $lineTotal;

            $item = new SaleItem();
            $item->setProduct($product);
            $item->setDescription($product->getName());
            $item->setQty($qty);
            $item->setUnitPrice(number_format($unitPrice, 2, '.', ''));
            $item->setLineTotal(number_format($lineTotal, 2, '.', ''));
            $sale->addItem($item);

            $product->adjustStock(-$qty);

            $movement = new StockMovement();
            $movement->setProduct($product);
            $movement->setType(StockMovement::TYPE_SALE);
            $movement->setQty(-$qty);
            $movement->setReference('Venta');
            $movement->setCreatedBy($user);

            $this->entityManager->persist($movement);
        }

        if (count($sale->getItems()) === 0) {
            $this->addFlash('danger', 'Agregá al menos un producto válido para registrar la venta.');

            return $this->redirectToRoute('app_sale_new');
        }

        $sale->setTotal(number_format($total, 2, '.', ''));

        $payment = new Payment();
        $payment->setAmount($sale->getTotal());
        $payment->setMethod('CASH');
        $sale->addPayment($payment);

        $this->entityManager->persist($sale);
        $this->entityManager->flush();

        $this->addFlash('success', 'Venta registrada.');

        return $this->redirectToRoute('app_sale_ticket', ['id' => $sale->getId()]);
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
