<?php

namespace App\Controller;

use App\Entity\Business;
use App\Entity\Product;
use App\Entity\PurchaseOrder;
use App\Entity\PurchaseOrderItem;
use App\Security\BusinessContext;
use App\Service\PurchaseSuggestionService;
use App\Service\PdfService;
use App\Service\PurchaseOrderSupplierEmailService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
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
                            <th>Email</th>
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
                                <td>
                                    {% if order.emailSentAt %}
                                        <span class="badge bg-success">Enviado</span>
                                    {% elseif order.emailFailedAt %}
                                        <span class="badge bg-danger">Error</span>
                                    {% endif %}
                                </td>
                                <td>{{ order.createdAt|date('d/m/Y H:i') }}</td>
                                <td class="text-end">
                                    <a class="btn btn-outline-secondary btn-sm" href="{{ path('app_purchase_order_edit', {id: order.id}) }}">Ver / editar</a>
                                </td>
                            </tr>
                            {% else %}
                            <tr>
                                <td colspan="6" class="text-muted">No hay pedidos todavía.</td>
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
            if ($purchaseOrder->getStatus() !== PurchaseOrder::STATUS_DRAFT) {
                $this->addFlash('warning', 'Solo se pueden editar pedidos en borrador.');

                return $this->redirectToRoute('app_purchase_order_edit', ['id' => $purchaseOrder->getId()]);
            }

            $purchaseOrder->setNotes($this->nullify($request->request->get('notes')));
            $invalidNumberResponse = function () use ($purchaseOrder): Response {
                $this->addFlash('danger', 'Cantidad o precio inválido.');

                return $this->redirectToRoute('app_purchase_order_edit', ['id' => $purchaseOrder->getId()]);
            };

            $itemData = $request->request->all('items');

            foreach ($purchaseOrder->getItems()->toArray() as $item) {
                $row = $itemData[$item->getId()] ?? null;
                if (!is_array($row)) {
                    continue;
                }
                $qtyRaw = (string) ($row['qty'] ?? '');
                $qty = $this->normalizeDecimalString($qtyRaw);
                if ($qty === null || $qty === '' || !preg_match('/^\d+(\.\d+)?$/', $qty)) {
                    return $invalidNumberResponse();
                }
                $qtyFloat = (float) $qty;
                if ($qtyFloat <= 0) {
                    return $invalidNumberResponse();
                }
                $unitCostRaw = (string) ($row['unitCost'] ?? '');
                if (trim($unitCostRaw) === '') {
                    $unitCost = '0.00';
                } else {
                    $unitCost = $this->normalizeDecimalString($unitCostRaw);
                    if ($unitCost === null || $unitCost === '' || !preg_match('/^\d+(\.\d+)?$/', $unitCost)) {
                        return $invalidNumberResponse();
                    }
                }
                $unitCostFloat = (float) $unitCost;
                if ($unitCostFloat < 0) {
                    return $invalidNumberResponse();
                }
                $item->setQuantity($qty);
                $item->setUnitCost($unitCost);
                $item->setSubtotal(bcmul($qty, $unitCost, 2));
            }

            $this->entityManager->flush();

            $this->addFlash('success', 'Pedido actualizado.');
            return $this->redirectToRoute('app_purchase_order_edit', ['id' => $purchaseOrder->getId()]);
        }

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
            <form method="post" action="{{ path('app_purchase_order_send_email', {id: order.id}) }}">
                <button class="btn btn-outline-secondary">Enviar por email</button>
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
            {% if order.status == 'DRAFT' %}
                <form method="post" class="vstack gap-3" id="purchase-order-form">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead>
                                <tr>
                                    <th>Producto</th>
                                    <th class="text-end">Cantidad</th>
                                    <th class="text-end">Costo unitario</th>
                                    <th class="text-end">Subtotal</th>
                                    <th class="text-center">Quitar</th>
                                </tr>
                            </thead>
                            <tbody>
                                {% for item in order.items %}
                                    <tr>
                                        <td>{{ item.product.name }}</td>
                                        <td class="text-end">
                                            <input class="form-control form-control-sm text-end" name="items[{{ item.id }}][qty]" value="{{ item.quantity }}">
                                        </td>
                                        <td class="text-end">
                                            <input class="form-control form-control-sm text-end" name="items[{{ item.id }}][unitCost]" value="{{ item.unitCost }}">
                                        </td>
                                        <td class="text-end">{{ item.subtotal }}</td>
                                        <td class="text-center">
                                            <button class="btn btn-link text-danger p-0" type="submit" formmethod="post" formaction="{{ path('app_purchase_order_item_remove', {id: order.id, itemId: item.id}) }}">✕</button>
                                        </td>
                                    </tr>
                                {% else %}
                                    <tr>
                                        <td colspan="5" class="text-muted">No hay items en este pedido.</td>
                                    </tr>
                                {% endfor %}
                            </tbody>
                        </table>
                    </div>
                    <div>
                        <label class="form-label">Notas</label>
                        <textarea class="form-control" name="notes" rows="2">{{ order.notes }}</textarea>
                    </div>
                    <div class="d-flex gap-2 align-items-center">
                        <button class="btn btn-primary">Guardar cambios</button>
                    </div>
                </form>
                <form method="post" action="{{ path('app_purchase_order_item_add', {id: order.id}) }}" class="border rounded p-3 mt-3" id="purchase-order-add-form">
                    <h3 class="h6 fw-semibold">Agregar producto</h3>
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label">Producto</label>
                            <input class="form-control form-control-sm" name="product_search" list="supplier-products" autocomplete="off" placeholder="Nombre, SKU, código prov o barras">
                            <datalist id="supplier-products"></datalist>
                            <input type="hidden" name="product_id">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Cantidad</label>
                            <input class="form-control form-control-sm" name="qty" type="text" inputmode="decimal" autocomplete="off">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Costo unitario</label>
                            <input class="form-control form-control-sm" name="unitCost" type="text" inputmode="decimal" autocomplete="off">
                        </div>
                    </div>
                    <p class="small text-muted mb-2 mt-2">Solo se permiten productos del mismo proveedor.</p>
                    <div class="d-flex gap-2 align-items-center">
                        <button class="btn btn-outline-primary" type="submit">Agregar producto</button>
                    </div>
                </form>
            {% else %}
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
                                    <td class="text-end">{{ item.quantity }}</td>
                                    <td class="text-end">{{ item.unitCost }}</td>
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
                <div class="mt-3">
                    <label class="form-label">Notas</label>
                    <textarea class="form-control" rows="2" readonly>{{ order.notes }}</textarea>
                </div>
            {% endif %}
            <div class="mt-3 text-end">
                <span class="text-muted">Total pedido: </span>
                <span class="fw-semibold">{{ orderTotal }}</span>
            </div>
        </div>
    </div>
    {% if order.status == 'DRAFT' %}
        <script>
            (function () {
                const input = document.querySelector('[name="product_search"]');
                const hidden = document.querySelector('[name="product_id"]');
                const list = document.getElementById('supplier-products');
                const searchUrl = "{{ path('app_purchase_order_products', {id: order.id}) }}";
                const addForm = document.getElementById('purchase-order-add-form');
                if (!input || !list || !hidden) {
                    return;
                }
                const normalizeValue = (value) => value.trim().toLowerCase();
                const setHiddenFromList = (value) => {
                    const currentValue = normalizeValue(value ?? input.value);
                    const option = Array.from(list.options).find((opt) => normalizeValue(opt.value) === currentValue);
                    hidden.value = option ? option.dataset.id : '';
                };
                const updateOptions = async (value) => {
                    const term = value.trim();
                    if (term.length < 3) {
                        hidden.value = '';
                        list.innerHTML = '';
                        return;
                    }
                    const response = await fetch(`${searchUrl}?term=${encodeURIComponent(term)}`);
                    const data = await response.json();
                    list.innerHTML = '';
                    data.items.forEach((product) => {
                        const option = document.createElement('option');
                        const label = `${product.name} · SKU ${product.sku}${product.supplierSku ? ' · Prov ' + product.supplierSku : ''}${product.barcode ? ' · Barras ' + product.barcode : ''}`;
                        option.value = label;
                        option.dataset.id = product.id;
                        list.appendChild(option);
                    });
                    setHiddenFromList(term);
                };
                input.addEventListener('input', () => {
                    const currentValue = input.value;
                    const matchingOption = Array.from(list.options).find(
                        (opt) => normalizeValue(opt.value) === normalizeValue(currentValue),
                    );
                    if (matchingOption) {
                        hidden.value = matchingOption.dataset.id;
                        return;
                    }
                    updateOptions(currentValue);
                });
                input.addEventListener('change', () => setHiddenFromList());
                input.addEventListener('blur', () => setHiddenFromList());
                if (addForm) {
                    addForm.addEventListener('submit', () => setHiddenFromList());
                }
            })();
        </script>
    {% endif %}
{% endblock %}
TWIG;

        return new Response($this->twig->createTemplate($template)->render([
            'order' => $purchaseOrder,
            'orderTotal' => $orderTotal,
        ]));
    }

    #[Route('/{id}/items/add', name: 'item_add', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function addItem(Request $request, PurchaseOrder $purchaseOrder): Response
    {
        $business = $this->requireBusinessContext();
        $this->denyIfDifferentBusiness($purchaseOrder, $business);

        if ($purchaseOrder->getStatus() !== PurchaseOrder::STATUS_DRAFT) {
            $this->addFlash('warning', 'Solo se pueden modificar items en borrador.');

            return $this->redirectToRoute('app_purchase_order_edit', ['id' => $purchaseOrder->getId()]);
        }

        $productId = (int) $request->request->get('product_id');
        $qtyRaw = (string) $request->request->get('qty');
        $qty = $this->normalizeDecimalString($qtyRaw);
        if ($productId <= 0 || $qty === null || $qty === '' || !preg_match('/^\d+(\.\d+)?$/', $qty)) {
            $this->addFlash('danger', 'Cantidad o producto inválido.');

            return $this->redirectToRoute('app_purchase_order_edit', ['id' => $purchaseOrder->getId()]);
        }
        $qtyFloat = (float) $qty;
        if ($qtyFloat <= 0) {
            $this->addFlash('danger', 'Cantidad inválida.');

            return $this->redirectToRoute('app_purchase_order_edit', ['id' => $purchaseOrder->getId()]);
        }
        $unitCostRaw = (string) $request->request->get('unitCost');
        $unitCost = null;
        if (trim($unitCostRaw) !== '') {
            $unitCost = $this->normalizeDecimalString($unitCostRaw);
            if ($unitCost === null || $unitCost === '' || !preg_match('/^\d+(\.\d+)?$/', $unitCost)) {
                $this->addFlash('danger', 'Costo unitario inválido.');

                return $this->redirectToRoute('app_purchase_order_edit', ['id' => $purchaseOrder->getId()]);
            }
            $unitCostFloat = (float) $unitCost;
            if ($unitCostFloat < 0) {
                $this->addFlash('danger', 'Costo unitario inválido.');

                return $this->redirectToRoute('app_purchase_order_edit', ['id' => $purchaseOrder->getId()]);
            }
        }

        $product = $this->entityManager->getRepository(Product::class)->find($productId);
        if (!$product instanceof Product
            || $product->getBusiness()?->getId() !== $business->getId()
            || $product->getSupplier()?->getId() !== $purchaseOrder->getSupplier()?->getId()) {
            $this->addFlash('danger', 'El producto no pertenece al proveedor o al comercio actual.');

            return $this->redirectToRoute('app_purchase_order_edit', ['id' => $purchaseOrder->getId()]);
        }

        $existingItem = null;
        foreach ($purchaseOrder->getItems() as $currentItem) {
            if ($currentItem->getProduct()?->getId() === $productId) {
                $existingItem = $currentItem;
                break;
            }
        }

        if ($existingItem instanceof PurchaseOrderItem) {
            $mergedQty = bcadd($existingItem->getQuantity(), $qty, 3);
            $resolvedUnitCost = $unitCost ?? $existingItem->getUnitCost();
            $existingItem->setQuantity($mergedQty);
            $existingItem->setUnitCost($resolvedUnitCost);
            $existingItem->setSubtotal(bcmul($mergedQty, $resolvedUnitCost, 2));
        } else {
            $item = new PurchaseOrderItem();
            $item->setProduct($product);
            $item->setQuantity($qty);
            $resolvedUnitCost = $unitCost ?? ($product->getPurchasePrice() ?? $product->getCost() ?? '0.00');
            $item->setUnitCost($resolvedUnitCost);
            $item->setSubtotal(bcmul($qty, $resolvedUnitCost, 2));
            $purchaseOrder->addItem($item);
        }

        $this->entityManager->flush();
        $this->addFlash('success', 'Producto agregado al pedido.');

        return $this->redirectToRoute('app_purchase_order_edit', ['id' => $purchaseOrder->getId()]);
    }

    #[Route('/{id}/items/{itemId}/remove', name: 'item_remove', requirements: ['id' => '\\d+', 'itemId' => '\\d+'], methods: ['POST'])]
    public function removeItem(PurchaseOrder $purchaseOrder, int $itemId): Response
    {
        $business = $this->requireBusinessContext();
        $this->denyIfDifferentBusiness($purchaseOrder, $business);

        if ($purchaseOrder->getStatus() !== PurchaseOrder::STATUS_DRAFT) {
            $this->addFlash('warning', 'Solo se pueden modificar items en borrador.');

            return $this->redirectToRoute('app_purchase_order_edit', ['id' => $purchaseOrder->getId()]);
        }

        $item = $this->entityManager->getRepository(PurchaseOrderItem::class)->find($itemId);
        if (!$item instanceof PurchaseOrderItem || $item->getPurchaseOrder()?->getId() !== $purchaseOrder->getId()) {
            $this->addFlash('danger', 'El item no pertenece al pedido.');

            return $this->redirectToRoute('app_purchase_order_edit', ['id' => $purchaseOrder->getId()]);
        }

        $purchaseOrder->removeItem($item);
        $this->entityManager->remove($item);
        $this->entityManager->flush();
        $this->addFlash('success', 'Producto quitado del pedido.');

        return $this->redirectToRoute('app_purchase_order_edit', ['id' => $purchaseOrder->getId()]);
    }

    #[Route('/{id}/products', name: 'products', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function products(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $business = $this->requireBusinessContext();
        $this->denyIfDifferentBusiness($purchaseOrder, $business);

        $term = trim((string) $request->query->get('term'));
        if (mb_strlen($term) < 3) {
            return new JsonResponse(['items' => []]);
        }

        $qb = $this->entityManager->getRepository(Product::class)->createQueryBuilder('p')
            ->andWhere('p.business = :business')
            ->andWhere('p.supplier = :supplier')
            ->andWhere('p.name LIKE :term OR p.sku LIKE :term OR p.supplierSku LIKE :term OR p.barcode LIKE :term')
            ->setParameter('business', $business)
            ->setParameter('supplier', $purchaseOrder->getSupplier())
            ->setParameter('term', '%'.$term.'%')
            ->orderBy('p.name', 'ASC')
            ->setMaxResults(20);

        $products = $qb->getQuery()->getResult();

        return new JsonResponse([
            'items' => array_map(static fn (Product $product) => [
                'id' => $product->getId(),
                'name' => $product->getName(),
                'sku' => $product->getSku(),
                'supplierSku' => $product->getSupplierSku(),
                'barcode' => $product->getBarcode(),
            ], $products),
        ]);
    }

    #[Route('/{id}/send-email', name: 'send_email', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function sendEmail(PurchaseOrder $purchaseOrder, PurchaseOrderSupplierEmailService $supplierEmailService): Response
    {
        $business = $this->requireBusinessContext();
        $this->denyIfDifferentBusiness($purchaseOrder, $business);

        if ($purchaseOrder->getStatus() !== PurchaseOrder::STATUS_CONFIRMED) {
            $this->addFlash('warning', 'Solo se pueden enviar pedidos confirmados.');

            return $this->redirectToRoute('app_purchase_order_edit', ['id' => $purchaseOrder->getId()]);
        }

        $supplierEmail = trim((string) $purchaseOrder->getSupplier()?->getEmail());
        if ($supplierEmail === '') {
            $this->addFlash('danger', 'El proveedor no tiene email cargado.');

            return $this->redirectToRoute('app_purchase_order_edit', ['id' => $purchaseOrder->getId()]);
        }

        $result = $supplierEmailService->send($purchaseOrder);

        if (!$result['sent']) {
            $purchaseOrder->setEmailFailedAt(new \DateTimeImmutable());
            $this->entityManager->flush();
            $this->addFlash('danger', 'No se pudo enviar el email al proveedor.');

            return $this->redirectToRoute('app_purchase_order_edit', ['id' => $purchaseOrder->getId()]);
        }

        $purchaseOrder->setEmailSentAt(new \DateTimeImmutable());
        $purchaseOrder->setEmailFailedAt(null);
        $this->entityManager->flush();
        $this->addFlash('success', 'Email enviado al proveedor.');

        if ($result['pdf_failed']) {
            $this->addFlash('warning', 'El email se envió pero no se pudo adjuntar el PDF.');
        }

        return $this->redirectToRoute('app_purchase_order_edit', ['id' => $purchaseOrder->getId()]);
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

    private function normalizeDecimalString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $normalized = trim($value);
        if ($normalized === '') {
            return null;
        }
        $normalized = preg_replace('/\s+/', '', $normalized);
        $normalized = preg_replace('/[^\d\.,]/', '', $normalized);
        if ($normalized === '') {
            return null;
        }
        $lastComma = strrpos($normalized, ',');
        $lastDot = strrpos($normalized, '.');
        $lastSeparator = max($lastComma !== false ? $lastComma : -1, $lastDot !== false ? $lastDot : -1);
        if ($lastSeparator === -1) {
            return preg_replace('/[^\d]/', '', $normalized);
        }
        $integerPart = substr($normalized, 0, $lastSeparator);
        $decimalPart = substr($normalized, $lastSeparator + 1);
        $integerPart = preg_replace('/[^\d]/', '', $integerPart);
        $decimalPart = preg_replace('/[^\d]/', '', $decimalPart);
        if ($decimalPart === '') {
            return $integerPart;
        }

        return $integerPart . '.' . $decimalPart;
    }
}
