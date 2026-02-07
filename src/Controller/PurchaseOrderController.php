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
            $isXmlHttpRequest = $request->isXmlHttpRequest();
            $purchaseOrder->setNotes($this->nullify($request->request->get('notes')));

            if ($purchaseOrder->getStatus() === PurchaseOrder::STATUS_DRAFT) {
                $itemData = $request->request->all('items');

                foreach ($purchaseOrder->getItems()->toArray() as $item) {
                    $row = $itemData[$item->getId()] ?? null;
                    if (!is_array($row)) {
                        continue;
                    }
                    if (!empty($row['remove'])) {
                        $purchaseOrder->removeItem($item);
                        $this->entityManager->remove($item);
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

                $newItems = $request->request->all('new_items');
                foreach ($newItems as $newItem) {
                    if (!is_array($newItem)) {
                        continue;
                    }
                    $newProductId = (int) ($newItem['product_id'] ?? 0);
                    $newQty = (string) ($newItem['qty'] ?? '0');
                    if ($newProductId <= 0 || bccomp($newQty, '0', 3) <= 0) {
                        continue;
                    }
                    $product = $this->entityManager->getRepository(Product::class)->find($newProductId);
                    if ($product instanceof Product
                        && $product->getBusiness()?->getId() === $business->getId()
                        && $product->getSupplier()?->getId() === $purchaseOrder->getSupplier()?->getId()) {
                        $unitCostInput = (string) ($newItem['unitCost'] ?? '');
                        $existingItem = null;
                        foreach ($purchaseOrder->getItems() as $currentItem) {
                            if ($currentItem->getProduct()?->getId() === $newProductId) {
                                $existingItem = $currentItem;
                                break;
                            }
                        }
                        if ($existingItem instanceof PurchaseOrderItem) {
                            $mergedQty = bcadd($existingItem->getQuantity(), $newQty, 3);
                            $unitCost = $unitCostInput !== '' ? $unitCostInput : $existingItem->getUnitCost();
                            $existingItem->setQuantity($mergedQty);
                            $existingItem->setUnitCost($unitCost);
                            $existingItem->setSubtotal(bcmul($mergedQty, $unitCost, 2));
                        } else {
                            $item = new PurchaseOrderItem();
                            $item->setProduct($product);
                            $item->setQuantity($newQty);
                            $unitCost = $unitCostInput !== '' ? $unitCostInput : ($product->getPurchasePrice() ?? $product->getCost() ?? '0.00');
                            $item->setUnitCost($unitCost);
                            $item->setSubtotal(bcmul($newQty, $unitCost, 2));
                            $purchaseOrder->addItem($item);
                        }
                    } else {
                        if (!$isXmlHttpRequest) {
                            $this->addFlash('danger', 'El producto no pertenece al proveedor o al comercio actual.');
                        }
                    }
                }
            }

            $this->entityManager->flush();

            if ($isXmlHttpRequest) {
                return new JsonResponse(['ok' => true]);
            }

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
            <form method="post" class="vstack gap-3" id="purchase-order-form">
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
                                <th class="text-center">Quitar</th>
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
                                    <td class="text-center">
                                        {% if order.status == 'DRAFT' %}
                                            <input type="hidden" name="items[{{ item.id }}][remove]" value="0">
                                            <button class="btn btn-link text-danger p-0" type="button" data-action="remove-existing" data-item-id="{{ item.id }}">✕</button>
                                        {% else %}
                                            -
                                        {% endif %}
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
                    <div class="d-flex gap-2 align-items-center">
                        <button class="btn btn-primary">Guardar cambios</button>
                        <button class="btn btn-outline-primary" type="button" data-action="add-item">Agregar producto</button>
                        <span class="spinner-border spinner-border-sm text-secondary d-none" id="autosave-spinner" role="status" aria-hidden="true"></span>
                    </div>
                    <p class="small text-muted mb-0" id="autosave-status"></p>
                {% else %}
                    <div class="d-flex gap-2 align-items-center">
                        <button class="btn btn-outline-primary">Guardar notas</button>
                    </div>
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
            const input = document.querySelector('[name=\"new_product_search\"]');
            const hidden = document.querySelector('[name=\"new_product_id\"]');
            const list = document.getElementById('supplier-products');
            const searchUrl = "{{ path('app_purchase_order_products', {id: order.id}) }}";
            const addButton = document.querySelector('[data-action=\"add-item\"]');
            const tableBody = document.querySelector('table.table tbody');
            const form = document.getElementById('purchase-order-form');
            const autosaveStatus = document.getElementById('autosave-status');
            const autosaveSpinner = document.getElementById('autosave-spinner');
            const saveButton = form ? form.querySelector('button.btn.btn-primary') : null;
            const addItemButton = document.querySelector('[data-action="add-item"]');
            let newIndex = 0;
            if (tableBody) {
                const existingIndexes = Array.from(tableBody.querySelectorAll('input[name^="new_items["]'))
                    .map((element) => {
                        const match = element.name.match(/^new_items\[(\d+)\]/);
                        return match ? Number.parseInt(match[1], 10) : null;
                    })
                    .filter((value) => Number.isInteger(value));
                if (existingIndexes.length > 0) {
                    newIndex = Math.max(...existingIndexes) + 1;
                }
            }
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

            let autosaveTimer;
            let autosaveNeedsReload = false;
            let autosaveDirty = false;
            let autosaveInFlight = null;
            let finalSubmitRequested = false;
            const setControlsDisabled = (disabled) => {
                if (saveButton) {
                    saveButton.disabled = disabled;
                }
                if (addItemButton) {
                    addItemButton.disabled = disabled;
                }
                if (!tableBody) {
                    return;
                }
                tableBody.querySelectorAll('[data-action=\"remove-new\"], [data-action=\"remove-existing\"]').forEach((button) => {
                    button.disabled = disabled;
                });
            };
            const saveOrder = (force = false) => {
                if (!form) {
                    return Promise.resolve();
                }
                if (!force && !autosaveDirty) {
                    return Promise.resolve();
                }
                autosaveDirty = false;
                if (autosaveTimer) {
                    clearTimeout(autosaveTimer);
                    autosaveTimer = null;
                }
                if (autosaveStatus) {
                    autosaveStatus.textContent = 'Guardando cambios...';
                }
                if (autosaveSpinner) {
                    autosaveSpinner.classList.remove('d-none');
                }
                setControlsDisabled(true);
                const formData = new FormData(form);
                autosaveInFlight = fetch(form.action || window.location.href, {
                    method: 'POST',
                    body: formData,
                    headers: {'X-Requested-With': 'XMLHttpRequest'},
                })
                    .then(() => {
                        if (autosaveNeedsReload) {
                            window.location.reload();
                            return;
                        }
                        if (autosaveStatus) {
                            autosaveStatus.textContent = 'Cambios guardados.';
                        }
                        if (autosaveSpinner) {
                            autosaveSpinner.classList.add('d-none');
                        }
                        setControlsDisabled(false);
                    })
                    .catch(() => {
                        if (autosaveStatus) {
                            autosaveStatus.textContent = 'No se pudieron guardar los cambios.';
                        }
                        if (autosaveSpinner) {
                            autosaveSpinner.classList.add('d-none');
                        }
                        setControlsDisabled(false);
                    })
                    .finally(() => {
                        autosaveInFlight = null;
                    });

                return autosaveInFlight;
            };

            const scheduleAutosave = (delayMs = 300) => {
                if (!form) {
                    return;
                }
                autosaveDirty = true;
                if (autosaveTimer) {
                    clearTimeout(autosaveTimer);
                }
                autosaveTimer = setTimeout(() => {
                    saveOrder(false);
                }, delayMs);
            };

            if (addButton && tableBody) {
                addButton.addEventListener('click', async () => {
                    setHiddenFromList();
                    await saveOrder(true);
                    const productId = hidden.value;
                    const label = input.value.trim();
                    const qtyInput = document.querySelector('[name=\"new_qty\"]');
                    const unitCostInput = document.querySelector('[name=\"new_unit_cost\"]');
                    const qty = qtyInput ? qtyInput.value.trim() : '';
                    const unitCost = unitCostInput ? unitCostInput.value.trim() : '';

                    if (!productId) {
                        alert('Seleccioná un producto de la lista sugerida.');
                        return;
                    }
                    if (!qty || parseFloat(qty) <= 0) {
                        alert('Ingresá una cantidad válida.');
                        return;
                    }

                    const emptyRow = tableBody.querySelector('tr td[colspan]');
                    if (emptyRow) {
                        emptyRow.closest('tr').remove();
                    }
                    const row = document.createElement('tr');
                    row.dataset.newItem = 'true';
                    row.innerHTML = `
                        <td>${label}<input type="hidden" name="new_items[${newIndex}][product_id]" value="${productId}"></td>
                        <td class="text-end"><input class="form-control form-control-sm text-end" name="new_items[${newIndex}][qty]" value="${qty}"></td>
                        <td class="text-end"><input class="form-control form-control-sm text-end" name="new_items[${newIndex}][unitCost]" value="${unitCost}"></td>
                        <td class="text-end">-</td>
                        <td class="text-center"><button class="btn btn-link text-danger p-0" type="button" data-action="remove-new">Quitar</button></td>
                    `;
                    tableBody.appendChild(row);
                    newIndex += 1;
                    autosaveNeedsReload = true;

                    input.value = '';
                    hidden.value = '';
                    if (qtyInput) qtyInput.value = '';
                    if (unitCostInput) unitCostInput.value = '';
                    autosaveDirty = true;
                    saveOrder(true);
                });

                tableBody.addEventListener('click', (event) => {
                    const target = event.target;
                    if (target && target.matches('[data-action=\"remove-new\"]')) {
                        event.preventDefault();
                        const row = target.closest('tr');
                        if (row) {
                            row.remove();
                        }
                        autosaveNeedsReload = true;
                        autosaveDirty = true;
                        saveOrder(true);
                    }
                    if (target && target.matches('[data-action=\"remove-existing\"]')) {
                        event.preventDefault();
                        const row = target.closest('tr');
                        if (row) {
                            const itemId = target.getAttribute('data-item-id');
                            const removeInput = row.querySelector(`input[name=\"items[${itemId}][remove]\"]`);
                            if (removeInput) {
                                removeInput.value = '1';
                            }
                            row.querySelectorAll('input').forEach((input) => {
                                if (input !== removeInput) {
                                    input.disabled = true;
                                }
                            });
                            row.style.display = 'none';
                        }
                        autosaveDirty = true;
                        saveOrder(true);
                    }
                });
            }

            if (form) {
                form.addEventListener('submit', (event) => {
                    if (finalSubmitRequested) {
                        finalSubmitRequested = false;
                        if (saveButton) {
                            saveButton.disabled = true;
                        }
                        return;
                    }
                    if (autosaveTimer || autosaveNeedsReload || autosaveDirty || autosaveInFlight) {
                        event.preventDefault();
                        Promise.resolve(autosaveInFlight)
                            .then(() => saveOrder(true))
                            .then(() => {
                                finalSubmitRequested = true;
                                form.submit();
                            });
                        return;
                    }
                    if (saveButton) {
                        saveButton.disabled = true;
                    }
                });
                form.addEventListener('input', (event) => {
                    if (event.target && event.target.closest('[data-action=\"add-item\"]')) {
                        return;
                    }
                    if (event.target && event.target.matches('input[name$=\"[qty]\"], input[name$=\"[unitCost]\"], input[name=\"new_qty\"], input[name=\"new_unit_cost\"]')) {
                        scheduleAutosave(300);
                    }
                });
                form.addEventListener('change', (event) => {
                    if (event.target && event.target.closest('[data-action=\"add-item\"]')) {
                        return;
                    }
                    scheduleAutosave(300);
                });
                form.addEventListener('blur', (event) => {
                    if (event.target && event.target.closest('[data-action=\"add-item\"]')) {
                        return;
                    }
                    if (event.target && event.target.matches('input[name$=\"[qty]\"], input[name$=\"[unitCost]\"], input[name=\"new_qty\"], input[name=\"new_unit_cost\"]')) {
                        saveOrder(false);
                    }
                }, true);
            }
        })();
    </script>
{% endblock %}
TWIG;

        return new Response($this->twig->createTemplate($template)->render([
            'order' => $purchaseOrder,
            'orderTotal' => $orderTotal,
        ]));
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
}
