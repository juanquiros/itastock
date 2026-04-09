<?php

namespace App\Controller;

use App\Entity\Business;
use App\Entity\PurchaseInvoice;
use App\Entity\PurchaseOrder;
use App\Entity\Supplier;
use App\Entity\SupplierPayment;
use App\Entity\User;
use App\Repository\CashSessionRepository;
use App\Repository\SupplierPaymentRepository;
use App\Security\BusinessContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('BUSINESS_ADMIN')]
#[Route('/app/supplier-payments', name: 'app_supplier_payment_')]
class SupplierPaymentController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BusinessContext $businessContext,
        private readonly SupplierPaymentRepository $supplierPaymentRepository,
        private readonly CashSessionRepository $cashSessionRepository,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $business = $this->requireBusinessContext();

        return $this->render('supplier_payment/index.html.twig', [
            'payments' => $this->supplierPaymentRepository->findRecentForBusiness($business, 100),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $business = $this->requireBusinessContext();
        $user = $this->requireUser();
        $openSession = $this->cashSessionRepository->findOpenForUser($business, $user);

        if ($openSession === null) {
            $this->addFlash('danger', 'Debés tener una caja abierta para registrar un pago a proveedor.');

            return $this->redirectToRoute('app_supplier_payment_index');
        }

        $suppliers = $this->entityManager->getRepository(Supplier::class)
            ->findBy(['business' => $business, 'active' => true], ['name' => 'ASC']);
        $orders = $this->entityManager->getRepository(PurchaseOrder::class)
            ->findBy(['business' => $business], ['createdAt' => 'DESC'], 200);
        $invoices = $this->entityManager->getRepository(PurchaseInvoice::class)
            ->findBy(['business' => $business], ['createdAt' => 'DESC'], 200);

        if ($request->isMethod('POST')) {
            $error = $this->validatePostData($request, $business, $supplier, $purchaseOrder, $purchaseInvoice, $amount, $method, $paidAt);

            if ($error !== null) {
                $this->addFlash('danger', $error);
            } else {
                $payment = new SupplierPayment();
                $payment
                    ->setBusiness($business)
                    ->setSupplier($supplier)
                    ->setPurchaseOrder($purchaseOrder)
                    ->setPurchaseInvoice($purchaseInvoice)
                    ->setAmount(number_format($amount, 2, '.', ''))
                    ->setPaymentMethod($method)
                    ->setPaidAt($paidAt)
                    ->setCreatedBy($user)
                    ->setReferenceNumber($this->nullify($request->request->get('reference_number')))
                    ->setNotes($this->nullify($request->request->get('notes')));

                $this->entityManager->persist($payment);
                $this->entityManager->flush();

                $this->addFlash('success', 'Pago a proveedor registrado.');

                return $this->redirectToRoute('app_supplier_payment_index');
            }
        }

        return $this->render('supplier_payment/new.html.twig', [
            'suppliers' => $suppliers,
            'orders' => $orders,
            'invoices' => $invoices,
            'allowedMethods' => SupplierPayment::allowedMethods(),
        ]);
    }

    private function requireBusinessContext(): Business
    {
        return $this->businessContext->requireCurrentBusiness();
    }

    private function requireUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedException('Debés iniciar sesión para operar.');
        }

        return $user;
    }

    private function nullify(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function validatePostData(
        Request $request,
        Business $business,
        ?Supplier &$supplier,
        ?PurchaseOrder &$purchaseOrder,
        ?PurchaseInvoice &$purchaseInvoice,
        ?float &$amount,
        ?string &$method,
        ?\DateTimeImmutable &$paidAt,
    ): ?string {
        $supplier = $this->entityManager->getRepository(Supplier::class)->find((int) $request->request->get('supplier_id'));
        if (!$supplier instanceof Supplier || $supplier->getBusiness()?->getId() !== $business->getId()) {
            return 'Seleccioná un proveedor válido del comercio actual.';
        }

        $purchaseOrder = null;
        $purchaseOrderId = (int) $request->request->get('purchase_order_id');
        if ($purchaseOrderId > 0) {
            $purchaseOrder = $this->entityManager->getRepository(PurchaseOrder::class)->find($purchaseOrderId);
            if (!$purchaseOrder instanceof PurchaseOrder || $purchaseOrder->getBusiness()?->getId() !== $business->getId()) {
                return 'El pedido relacionado no pertenece al comercio actual.';
            }
            if ($purchaseOrder->getSupplier()?->getId() !== $supplier->getId()) {
                return 'El pedido relacionado debe ser del mismo proveedor.';
            }
        }

        $purchaseInvoice = null;
        $purchaseInvoiceId = (int) $request->request->get('purchase_invoice_id');
        if ($purchaseInvoiceId > 0) {
            $purchaseInvoice = $this->entityManager->getRepository(PurchaseInvoice::class)->find($purchaseInvoiceId);
            if (!$purchaseInvoice instanceof PurchaseInvoice || $purchaseInvoice->getBusiness()?->getId() !== $business->getId()) {
                return 'La compra relacionada no pertenece al comercio actual.';
            }
            if ($purchaseInvoice->getSupplier()?->getId() !== $supplier->getId()) {
                return 'La compra relacionada debe ser del mismo proveedor.';
            }
        }

        $amount = (float) $request->request->get('amount', 0);
        if ($amount <= 0) {
            return 'El monto debe ser mayor a 0.';
        }

        $method = strtoupper(trim((string) $request->request->get('payment_method', '')));
        if (!in_array($method, SupplierPayment::allowedMethods(), true)) {
            return 'Método de pago inválido. Usá CASH o TRANSFER.';
        }

        $paidAtRaw = trim((string) $request->request->get('paid_at'));
        if ($paidAtRaw === '') {
            return 'Indicá fecha y hora del pago.';
        }

        try {
            $paidAt = new \DateTimeImmutable($paidAtRaw);
        } catch (\Exception) {
            return 'La fecha y hora del pago no es válida.';
        }

        return null;
    }
}
