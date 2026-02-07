<?php

namespace App\Controller;

use App\Entity\Business;
use App\Entity\Product;
use App\Entity\PurchaseOrder;
use App\Entity\PurchaseOrderItem;
use App\Security\BusinessContext;
use App\Service\PurchaseSuggestionService;
use App\Service\PdfService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;

#[IsGranted('BUSINESS_ADMIN')]
#[Route('/app/admin/purchase-orders', name: 'app_purchase_order_')]
class PurchaseOrderController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BusinessContext $businessContext,
        private readonly PurchaseSuggestionService $purchaseSuggestionService,
        private readonly PdfService $pdfService,
        private readonly Environment $twig,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $business = $this->requireBusinessContext();

        $orders = $this->entityManager->getRepository(PurchaseOrder::class)
            ->findBy(['business' => $business], ['createdAt' => 'DESC']);

        $template = <<<'TWIG'
{% extends 'base.html.twig' %}

{% block title %}Pedidos a proveedores · ItaStock{% endblock %}

{% block body %}
    <div class="mb-4">
        <p class="text-uppercase text-muted mb-1 small fw-semibold">Administración</p>
        <h1 class="h4 mb-0">Pedidos a proveedores</h1>
        <p class="text-secondary mb-0">Borradores generados por stock bajo y seguimiento de estados.</p>
    </div>

    <div class="d-flex gap-2 mb-4">
        <form method="post" action="{{ path('app_purchase_order_generate') }}">
            <button class="btn btn-primary">Generar sugerencias</button>
        </form>
        <a class="btn btn-outline-secondary" href="{{ path('app_purchase_order_new') }}">Nuevo pedido</a>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Proveedor</th>
                            <th>Estado</th>
                            <th>Creado</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        {% for order in orders %}
                            <tr>
                                <td>#{{ order.id }}</td>
                                <td>{{ order.supplier.name }}</td>
                                <td>
                                    <span class="badge bg-secondary">{{ order.status }}</span>
                                </td>
                                <td>{{ order.createdAt|date('d/m/Y H:i') }}</td>
                                <td class="text-end">
                                    <a class="btn btn-outline-secondary btn-sm" href="{{ path('app_purchase_order_edit', {id: order.id}) }}">Ver / editar</a>
                                </td>
                            </tr>
                        {% else %}
                            <tr>
                                <td colspan="5" class="text-muted">No hay pedidos todavía.</td>
                            </tr>
                        {% endfor %}
                    </tbody>
                </table>
            </div>
        </div>
    </div>
{% endblock %}
TWIG;

        return new Response($this->twig->createTemplate($template)->render([
            'orders' => $orders,
        ]));
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $business = $this->requireBusinessContext();
        $suppliers = $this->entityManager->getRepository(\App\Entity\Supplier::class)
            ->findBy(['business' => $business, 'active' => true], ['name' => 'ASC']);

        if ($request->isMethod('POST')) {
            $supplierId = (int) $request->request->get('supplier_id');
            $supplier = $this->entityManager->getRepository(\App\Entity\Supplier::class)->find($supplierId);
            if ($supplier === null || $supplier->getBusiness()?->getId() !== $business->getId()) {
                $this->addFlash('danger', 'Seleccioná un proveedor válido.');
            } else {
                $order = new PurchaseOrder();
                $order->setBusiness($business);
                $order->setSupplier($supplier);
                $this->entityManager->persist($order);
                $this->entityManager->flush();

                return $this->redirectToRoute('app_purchase_order_edit', ['id' => $order->getId()]);
            }
        }

        $template = <<<'TWIG'
{% extends 'base.html.twig' %}

{% block title %}Nuevo pedido · ItaStock{% endblock %}

{% block body %}
    <div class="mb-4">
        <p class="text-uppercase text-muted mb-1 small fw-semibold">Administración</p>
        <h1 class="h4 mb-0">Nuevo pedido a proveedor</h1>
        <p class="text-secondary mb-0">Seleccioná el proveedor para empezar a cargar productos.</p>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
            <form method="post" class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Proveedor</label>
                    <select class="form-select" name="supplier_id" required>
                        <option value="">Seleccionar</option>
                        {% for supplier in suppliers %}
                            <option value="{{ supplier.id }}">{{ supplier.name }}</option>
                        {% endfor %}
                    </select>
                </div>
                <div class="col-12 d-flex gap-2">
                    <button class="btn btn-primary">Crear pedido</button>
                    <a class="btn btn-outline-secondary" href="{{ path('app_purchase_order_index') }}">Volver</a>
                </div>
            </form>
        </div>
    </div>
{% endblock %}
TWIG;

        return new Response($this->twig->createTemplate($template)->render([
            'suppliers' => $suppliers,
        ]));
    }

    #[Route('/generate', name: 'generate', methods: ['POST'])]
    public function generate(): Response
    {
        $business = $this->requireBusinessContext();
        $orders = $this->purchaseSuggestionService->createDraftOrders($business);

        if ($orders === []) {
            $this->addFlash('info', 'No se encontraron productos con stock bajo y proveedor asignado.');
        } else {
            $this->addFlash('success', sprintf('Se generaron %d pedidos en borrador.', count($orders)));
        }

        return $this->redirectToRoute('app_purchase_order_index');
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, PurchaseOrder $purchaseOrder): Response
    {
        $business = $this->requireBusinessContext();
        $this->denyIfDifferentBusiness($purchaseOrder, $business);

        if ($request->isMethod('POST')) {
            $purchaseOrder->setNotes($this->nullify($request->request->get('notes')));

            if ($purchaseOrder->getStatus() === PurchaseOrder::STATUS_DRAFT) {
                $itemData = $request->request->all('items');

                foreach ($purchaseOrder->getItems() as $item) {
                    $row = $itemData[$item->getId()] ?? null;
                    if (!is_array($row)) {
                        continue;
                    }
                    $qty = (string) ($row['qty'] ?? '0');
                    if (bccomp($qty, '0', 3) <= 0) {
                        $purchaseOrder->removeItem($item);
                        $this->entityManager->remove($item);
                        continue;
                    }
                    $unitCost = (string) ($row['unitCost'] ?? '0.00');
                    $item->setQuantity($qty);
                    $item->setUnitCost($unitCost);
                    $item->setSubtotal(bcmul($qty, $unitCost, 2));
                }

                $newProductId = (int) $request->request->get('new_product_id');
                $newQty = (string) $request->request->get('new_qty');
                if ($newProductId > 0 && bccomp($newQty, '0', 3) > 0) {
                    $product = $this->entityManager->getRepository(Product::class)->find($newProductId);
                    if ($product instanceof Product
                        && $product->getBusiness()?->getId() === $business->getId()
                        && $product->getSupplier()?->getId() === $purchaseOrder->getSupplier()?->getId()) {
                        $item = new PurchaseOrderItem();
                        $item->setProduct($product);
                        $item->setQuantity($newQty);
                        $unitCostInput = (string) $request->request->get('new_unit_cost');
                        $unitCost = $unitCostInput !== '' ? $unitCostInput : ($product->getPurchasePrice() ?? $product->getCost() ?? '0.00');
                        $item->setUnitCost($unitCost);
                        $item->setSubtotal(bcmul($newQty, $unitCost, 2));
                        $purchaseOrder->addItem($item);
                    } else {
                        $this->addFlash('danger', 'El producto no pertenece al proveedor o al comercio actual.');
                    }
                }
            }

            $this->entityManager->flush();
            $this->addFlash('success', 'Pedido actualizado.');

            return $this->redirectToRoute('app_purchase_order_edit', ['id' => $purchaseOrder->getId()]);
        }

        $products = $this->entityManager->getRepository(Product::class)->createQueryBuilder('p')
            ->andWhere('p.business = :business')
            ->andWhere('p.supplier = :supplier')
            ->setParameter('business', $business)
            ->setParameter('supplier', $purchaseOrder->getSupplier())
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();

        $orderTotal = '0.00';
        foreach ($purchaseOrder->getItems() as $item) {
            $orderTotal = bcadd($orderTotal, $item->getSubtotal(), 2);
        }

        $template = <<<'TWIG'
{% extends 'base.html.twig' %}

{% block title %}Pedido #{{ order.id }} · ItaStock{% endblock %}

{% block body %}
    <div class="mb-4">
        <p class="text-uppercase text-muted mb-1 small fw-semibold">Administración</p>
        <h1 class="h4 mb-0">Pedido #{{ order.id }}</h1>
        <p class="text-secondary mb-0">
            Proveedor: {{ order.supplier.name }}
            {% if order.supplier.email %}
                · <a href="mailto:{{ order.supplier.email }}">{{ order.supplier.email }}</a>
            {% endif %}
            {% if order.supplier.phone %}
                · <a href="tel:{{ order.supplier.phone }}">{{ order.supplier.phone }}</a>
                · <a href="https://wa.me/{{ order.supplier.phone|replace({' ': '', '+': ''}) }}" target="_blank" rel="noopener">WhatsApp</a>
            {% endif %}
        </p>
    </div>

    <div class="d-flex gap-2 mb-4">
        {% if order.status == 'DRAFT' %}
            <form method="post" action="{{ path('app_purchase_order_confirm', {id: order.id}) }}">
                <button class="btn btn-primary">Confirmar pedido</button>
            </form>
        {% endif %}
        {% if order.status == 'CONFIRMED' %}
            <form method="post" action="{{ path('app_purchase_order_receive', {id: order.id}) }}">
                <button class="btn btn-outline-primary">Marcar recibido</button>
            </form>
        {% endif %}
        {% if order.status in ['DRAFT', 'CONFIRMED'] %}
            <form method="post" action="{{ path('app_purchase_order_cancel', {id: order.id}) }}">
                <button class="btn btn-outline-danger">Cancelar pedido</button>
            </form>
        {% endif %}
        {% if order.status == 'RECEIVED' %}
            <a class="btn btn-primary" href="{{ path('app_purchase_invoice_from_order', {id: order.id}) }}">Cargar compra</a>
        {% endif %}
        <a class="btn btn-outline-secondary" href="{{ path('app_purchase_order_pdf', {id: order.id}) }}">Descargar PDF</a>
        <a class="btn btn-outline-secondary" href="{{ path('app_purchase_order_index') }}">Volver</a>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <h2 class="h6 fw-semibold">Detalle del pedido</h2>
            <form method="post" class="vstack gap-3">
                <div>
                    <label class="form-label">Notas</label>
                    <textarea class="form-control" name="notes" rows="2">{{ order.notes }}</textarea>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th>Producto</th>
                                <th class="text-end">Cantidad</th>
                                <th class="text-end">Costo unitario</th>
                                <th class="text-end">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                            {% for item in order.items %}
                                <tr>
                                    <td>{{ item.product.name }}</td>
                                    <td class="text-end">
                                        {% if order.status == 'DRAFT' %}
                                            <input class="form-control form-control-sm text-end" name="items[{{ item.id }}][qty]" value="{{ item.quantity }}">
                                        {% else %}
                                            {{ item.quantity }}
                                        {% endif %}
                                    </td>
                                    <td class="text-end">
                                        {% if order.status == 'DRAFT' %}
                                            <input class="form-control form-control-sm text-end" name="items[{{ item.id }}][unitCost]" value="{{ item.unitCost }}">
                                        {% else %}
                                            {{ item.unitCost }}
                                        {% endif %}
                                    </td>
                                    <td class="text-end">{{ item.subtotal }}</td>
                                </tr>
                            {% else %}
                                <tr>
                                    <td colspan="4" class="text-muted">No hay items en este pedido.</td>
                                </tr>
                            {% endfor %}
                        </tbody>
                    </table>
                </div>
                {% if order.status == 'DRAFT' %}
                    <div class="border rounded p-3">
                        <h3 class="h6 fw-semibold">Agregar producto</h3>
                        <div class="row g-2">
                            <div class="col-md-6">
                                <label class="form-label">Producto</label>
                                <input class="form-control form-control-sm" name="new_product_search" list="supplier-products" autocomplete="off" placeholder="Nombre, SKU, código prov o barras">
                                <datalist id="supplier-products"></datalist>
                                <input type="hidden" name="new_product_id">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Cantidad</label>
                                <input class="form-control form-control-sm" name="new_qty" type="number" step="0.001">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Costo unitario</label>
                                <input class="form-control form-control-sm" name="new_unit_cost" type="number" step="0.01">
                            </div>
                        </div>
                        <p class="small text-muted mb-0 mt-2">Solo se permiten productos del mismo proveedor.</p>
                    </div>
                    <button class="btn btn-primary">Guardar cambios</button>
                {% endif %}
            </form>
            <div class="mt-3 text-end">
                <span class="text-muted">Total pedido: </span>
                <span class="fw-semibold">{{ orderTotal }}</span>
            </div>
        </div>
    </div>
    <script>
        (function () {
            const products = {{ products|json_encode|raw }};
            const input = document.querySelector('[name=\"new_product_search\"]');
            const hidden = document.querySelector('[name=\"new_product_id\"]');
            const list = document.getElementById('supplier-products');
            if (!input || !list || !hidden) {
                return;
            }
            const updateOptions = (value) => {
                const term = value.toLowerCase();
                list.innerHTML = '';
                const matches = products.filter((product) => {
                    const haystack = `${product.name} ${product.sku} ${product.supplierSku ?? ''} ${product.barcode ?? ''}`.toLowerCase();
                    return haystack.includes(term);
                }).slice(0, 20);
                matches.forEach((product) => {
                    const option = document.createElement('option');
                    const label = `${product.name} · SKU ${product.sku}${product.supplierSku ? ' · Prov ' + product.supplierSku : ''}${product.barcode ? ' · Barras ' + product.barcode : ''}`;
                    option.value = label;
                    option.dataset.id = product.id;
                    list.appendChild(option);
                });
            };
            input.addEventListener('input', () => {
                updateOptions(input.value);
                const option = Array.from(list.options).find((opt) => opt.value === input.value);
                hidden.value = option ? option.dataset.id : '';
            });
        })();
    </script>
{% endblock %}
TWIG;

        return new Response($this->twig->createTemplate($template)->render([
            'order' => $purchaseOrder,
            'products' => array_map(static fn (Product $product) => [
                'id' => $product->getId(),
                'name' => $product->getName(),
                'sku' => $product->getSku(),
                'supplierSku' => $product->getSupplierSku(),
                'barcode' => $product->getBarcode(),
            ], $products),
            'orderTotal' => $orderTotal,
        ]));
    }

    #[Route('/{id}/confirm', name: 'confirm', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function confirm(PurchaseOrder $purchaseOrder): Response
    {
        $business = $this->requireBusinessContext();
        $this->denyIfDifferentBusiness($purchaseOrder, $business);

        if ($purchaseOrder->getStatus() !== PurchaseOrder::STATUS_DRAFT) {
            $this->addFlash('warning', 'Solo se pueden confirmar pedidos en borrador.');
        } else {
            $purchaseOrder->setStatus(PurchaseOrder::STATUS_CONFIRMED);
            $this->entityManager->flush();
            $this->addFlash('success', 'Pedido confirmado.');
        }

        return $this->redirectToRoute('app_purchase_order_edit', ['id' => $purchaseOrder->getId()]);
    }

    #[Route('/{id}/receive', name: 'receive', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function receive(PurchaseOrder $purchaseOrder): Response
    {
        $business = $this->requireBusinessContext();
        $this->denyIfDifferentBusiness($purchaseOrder, $business);

        if ($purchaseOrder->getStatus() !== PurchaseOrder::STATUS_CONFIRMED) {
            $this->addFlash('warning', 'Solo se pueden recibir pedidos confirmados.');
        } else {
            foreach ($purchaseOrder->getItems() as $item) {
                $product = $item->getProduct();
                if ($product instanceof Product) {
                    $product->adjustStock($item->getQuantity());
                }
            }
            $purchaseOrder->setStatus(PurchaseOrder::STATUS_RECEIVED);
            $this->entityManager->flush();
            $this->addFlash('success', 'Pedido recibido y stock actualizado.');
        }

        return $this->redirectToRoute('app_purchase_order_edit', ['id' => $purchaseOrder->getId()]);
    }

    #[Route('/{id}/cancel', name: 'cancel', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function cancel(PurchaseOrder $purchaseOrder): Response
    {
        $business = $this->requireBusinessContext();
        $this->denyIfDifferentBusiness($purchaseOrder, $business);

        if ($purchaseOrder->getStatus() === PurchaseOrder::STATUS_RECEIVED) {
            $this->addFlash('warning', 'Un pedido recibido no se puede cancelar.');
        } elseif ($purchaseOrder->getStatus() === PurchaseOrder::STATUS_CANCELLED) {
            $this->addFlash('info', 'El pedido ya está cancelado.');
        } else {
            $purchaseOrder->setStatus(PurchaseOrder::STATUS_CANCELLED);
            $this->entityManager->flush();
            $this->addFlash('success', 'Pedido cancelado.');
        }

        return $this->redirectToRoute('app_purchase_order_edit', ['id' => $purchaseOrder->getId()]);
    }

    #[Route('/{id}/pdf', name: 'pdf', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function pdf(PurchaseOrder $purchaseOrder): Response
    {
        $business = $this->requireBusinessContext();
        $this->denyIfDifferentBusiness($purchaseOrder, $business);

        $orderTotal = '0.00';
        foreach ($purchaseOrder->getItems() as $item) {
            $orderTotal = bcadd($orderTotal, $item->getSubtotal(), 2);
        }

        return $this->pdfService->render('purchase_order/pdf.html.twig', [
            'order' => $purchaseOrder,
            'orderTotal' => $orderTotal,
            'generatedAt' => new \DateTimeImmutable(),
        ], sprintf('pedido-%d.pdf', $purchaseOrder->getId()));
    }

    private function requireBusinessContext(): Business
    {
        return $this->businessContext->requireCurrentBusiness();
    }

    private function denyIfDifferentBusiness(PurchaseOrder $purchaseOrder, Business $business): void
    {
        if ($purchaseOrder->getBusiness()?->getId() !== $business->getId()) {
            throw $this->createAccessDeniedException();
        }
    }

    private function nullify(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === '' ? null : $value;
    }
}
