<?php

namespace App\Controller;

use App\Entity\Business;
use App\Entity\FiscalComponent;
use App\Entity\PurchaseInvoice;
use App\Entity\PurchaseOrder;
use App\Entity\Supplier;
use App\Security\BusinessContext;
use App\Service\FiscalManualComponentFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;

#[IsGranted('BUSINESS_ADMIN')]
#[Route('/app/admin/purchase-invoices', name: 'app_purchase_invoice_')]
class PurchaseInvoiceController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BusinessContext $businessContext,
        private readonly Environment $twig,
        private readonly FiscalManualComponentFactory $fiscalManualComponentFactory,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $business = $this->requireBusinessContext();

        $invoices = $this->entityManager->getRepository(PurchaseInvoice::class)
            ->findBy(['business' => $business], ['createdAt' => 'DESC']);

        $template = <<<'TWIG'
{% extends 'base.html.twig' %}

{% block title %}Compras / IVA compras · ItaStock{% endblock %}

{% block body %}
    <div class="mb-4">
        <p class="text-uppercase text-muted mb-1 small fw-semibold">Administración</p>
        <h1 class="h4 mb-0">Compras / IVA compras</h1>
        <p class="text-secondary mb-0">Registrá facturas de proveedores y mantené IVA compras al día.</p>
    </div>

    
    <div class="alert alert-warning py-2 small" role="alert">
        Registrar/confirmar este flujo no genera un pago al proveedor. Los pagos se cargan aparte en <strong>Pagos a proveedores</strong>.
    </div>

<div class="d-flex gap-2 mb-4">
        <a class="btn btn-primary" href="{{ path('app_purchase_invoice_new') }}">Nueva compra</a>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Proveedor</th>
                            <th>Tipo</th>
                            <th>Factura</th>
                            <th>Fecha</th>
                            <th>Total</th>
                            <th>Estado</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        {% for invoice in invoices %}
                            <tr>
                                <td>#{{ invoice.id }}</td>
                                <td>{{ invoice.supplier.name }}</td>
                                <td>{{ invoice.invoiceType }}</td>
                                <td>{{ invoice.pointOfSale ?: '' }} {{ invoice.invoiceNumber }}</td>
                                <td>{{ invoice.invoiceDate|date('d/m/Y') }}</td>
                                <td>
                                    {{ invoice.totalAmount }}
                                    {% if invoice.fiscalComponentsTotal is defined and invoice.fiscalComponentsTotal > 0 %}
                                        <span class="badge text-bg-info ms-1">Con tributos</span>
                                    {% endif %}
                                </td>
                                <td>
                                    <span class="badge bg-secondary">{{ invoice.status }}</span>
                                </td>
                                <td class="text-end">
                                    {% if invoice.status == 'DRAFT' %}
                                        <form method="post" action="{{ path('app_purchase_invoice_confirm', {id: invoice.id}) }}">
                                            <button class="btn btn-outline-primary btn-sm">Confirmar</button>
                                        </form>
                                    {% endif %}
                                </td>
                            </tr>
                        {% else %}
                            <tr>
                                <td colspan="8" class="text-muted">No hay compras registradas.</td>
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
            'invoices' => $invoices,
        ]));
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $business = $this->requireBusinessContext();
        $suppliers = $this->entityManager->getRepository(Supplier::class)
            ->findBy(['business' => $business, 'active' => true], ['name' => 'ASC']);

        if ($request->isMethod('POST')) {
            $supplierId = (int) $request->request->get('supplier_id');
            $supplier = $this->entityManager->getRepository(Supplier::class)->find($supplierId);
            if (!$supplier instanceof Supplier || $supplier->getBusiness()?->getId() !== $business->getId()) {
                $this->addFlash('danger', 'Seleccioná un proveedor válido.');
            } else {
                try {
                    $invoice = $this->hydrateInvoice(new PurchaseInvoice(), $request, $business, $supplier);
                    $this->entityManager->persist($invoice);
                    $this->entityManager->flush();

                    $this->addFlash('success', 'Compra registrada en borrador.');

                    return $this->redirectToRoute('app_purchase_invoice_index');
                } catch (\InvalidArgumentException $exception) {
                    $this->addFlash('danger', $exception->getMessage());
                }
            }
        }

        $template = <<<'TWIG'
{% extends 'base.html.twig' %}

{% block title %}Nueva compra · ItaStock{% endblock %}

{% block body %}
    <div class="mb-4">
        <p class="text-uppercase text-muted mb-1 small fw-semibold">Administración</p>
        <h1 class="h4 mb-0">Nueva compra</h1>
        <p class="text-secondary mb-0">Cargá facturas o tickets de proveedores. Este paso no registra pagos al proveedor.</p>
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
                <div class="col-md-3">
                    <label class="form-label">Tipo</label>
                    <select class="form-select" name="invoice_type">
                        <option value="FACTURA">Factura</option>
                        <option value="TICKET">Ticket</option>
                        <option value="NC">Nota de crédito</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Fecha</label>
                    <input class="form-control" type="date" name="invoice_date" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Punto de venta</label>
                    <input class="form-control" name="point_of_sale">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Número</label>
                    <input class="form-control" name="invoice_number" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Neto</label>
                    <input class="form-control" name="net_amount" type="number" step="0.01" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">IVA %</label>
                    <input class="form-control" name="iva_rate" type="number" step="0.01" value="21.00" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">IVA $</label>
                    <input class="form-control" name="iva_amount" type="number" step="0.01" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Total</label>
                    <input class="form-control" name="total_amount" type="number" step="0.01" required>
                </div>
                <div class="col-12">
                    <label class="form-label">Notas</label>
                    <textarea class="form-control" name="notes" rows="2"></textarea>
                </div>
                <div class="col-12">
                    <button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#fiscal-components-collapse" aria-expanded="false" aria-controls="fiscal-components-collapse">
                        Tributos / percepciones sufridas
                    </button>
                    <div class="form-text">Cargar aquí impuestos internos, percepciones u otros tributos discriminados en la factura del proveedor.</div>
                    <div class="collapse mt-2" id="fiscal-components-collapse">
                        <div id="fiscal-components-rows" class="d-flex flex-column gap-2"></div>
                        <button type="button" class="btn btn-sm btn-outline-primary mt-2" id="add-fiscal-component">Agregar tributo</button>
                    </div>
                </div>
                <div class="col-12 d-flex gap-2">
                    <button class="btn btn-primary">Guardar compra</button>
                    <a class="btn btn-outline-secondary" href="{{ path('app_purchase_invoice_index') }}">Volver</a>
                </div>
            </form>
        </div>
    </div>
    <script>
        (() => {
            const rows = document.getElementById('fiscal-components-rows');
            const addBtn = document.getElementById('add-fiscal-component');
            if (!rows || !addBtn) return;
            let idx = 0;
            const buildRow = (i) => {
                const wrap = document.createElement('div');
                wrap.className = 'border rounded p-2';
                wrap.innerHTML = `<div class="row g-2">
                    <div class="col-md-3"><select class="form-select form-select-sm" name="fiscal_components[${i}][componentType]"><option value="OTHER">Otro</option><option value="INTERNAL_TAX">Impuestos internos</option><option value="IIBB_PERCEPTION">Percepción Ingresos Brutos</option><option value="VAT_PERCEPTION">Percepción IVA</option><option value="MUNICIPAL_TAX">Tasa municipal</option><option value="NATIONAL_OTHER_TAX">Otro impuesto nacional</option></select></div>
                    <div class="col-md-5"><input class="form-control form-control-sm" name="fiscal_components[${i}][description]" placeholder="Descripción"></div>
                    <div class="col-md-4"><input class="form-control form-control-sm" name="fiscal_components[${i}][jurisdiction]" placeholder="Jurisdicción"></div>
                    <div class="col-md-3"><input class="form-control form-control-sm" name="fiscal_components[${i}][taxableBase]" placeholder="Base imponible"></div>
                    <div class="col-md-2"><input class="form-control form-control-sm" name="fiscal_components[${i}][rate]" placeholder="Alícuota %"></div>
                    <div class="col-md-2"><input class="form-control form-control-sm" name="fiscal_components[${i}][amount]" placeholder="Importe"></div>
                    <div class="col-md-2"><input class="form-control form-control-sm" name="fiscal_components[${i}][arcaTributeId]" placeholder="Cód. ARCA"></div>
                    <div class="col-md-2 d-flex align-items-center"><input type="hidden" name="fiscal_components[${i}][affectsTotal]" value="0"><input class="form-check-input me-1" type="checkbox" name="fiscal_components[${i}][affectsTotal]" value="1" checked><label class="form-check-label small">Afecta total</label></div>
                    <div class="col-md-3 d-flex align-items-center"><input type="hidden" name="fiscal_components[${i}][reportToArca]" value="0"><input class="form-check-input me-1" type="checkbox" name="fiscal_components[${i}][reportToArca]" value="1"><label class="form-check-label small">Informar ARCA</label></div>
                    <div class="col-12 text-end"><button type="button" class="btn btn-link btn-sm text-danger remove-row">Quitar</button></div>
                </div>`;
                wrap.querySelector('.remove-row')?.addEventListener('click', () => wrap.remove());
                return wrap;
            };
            addBtn.addEventListener('click', () => rows.appendChild(buildRow(idx++)));
        })();
    </script>
{% endblock %}
TWIG;

        return new Response($this->twig->createTemplate($template)->render([
            'suppliers' => $suppliers,
        ]));
    }

    #[Route('/from-order/{id}', name: 'from_order', requirements: ['id' => '\\d+'], methods: ['GET', 'POST'])]
    public function fromOrder(Request $request, PurchaseOrder $purchaseOrder): Response
    {
        $business = $this->requireBusinessContext();
        $this->denyIfDifferentBusiness($purchaseOrder, $business);

        if ($purchaseOrder->getStatus() !== PurchaseOrder::STATUS_RECEIVED) {
            $this->addFlash('warning', 'El pedido debe estar recibido para generar la compra.');
            return $this->redirectToRoute('app_purchase_order_edit', ['id' => $purchaseOrder->getId()]);
        }

        if ($request->isMethod('POST')) {
            try {
                $invoice = $this->hydrateInvoice(new PurchaseInvoice(), $request, $business, $purchaseOrder->getSupplier());
                $invoice->setPurchaseOrder($purchaseOrder);
                $this->entityManager->persist($invoice);
                $this->entityManager->flush();

                $this->addFlash('success', 'Compra registrada desde el pedido.');

                return $this->redirectToRoute('app_purchase_invoice_index');
            } catch (\InvalidArgumentException $exception) {
                $this->addFlash('danger', $exception->getMessage());
            }
        }

        $template = <<<'TWIG'
{% extends 'base.html.twig' %}

{% block title %}Compra desde pedido · ItaStock{% endblock %}

{% block body %}
    <div class="mb-4">
        <p class="text-uppercase text-muted mb-1 small fw-semibold">Administración</p>
        <h1 class="h4 mb-0">Compra desde pedido #{{ order.id }}</h1>
        <p class="text-secondary mb-0">Proveedor: {{ order.supplier.name }}. Este flujo no registra pagos al proveedor.</p>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
            <form method="post" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Tipo</label>
                    <select class="form-select" name="invoice_type">
                        <option value="FACTURA">Factura</option>
                        <option value="TICKET">Ticket</option>
                        <option value="NC">Nota de crédito</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Fecha</label>
                    <input class="form-control" type="date" name="invoice_date" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Punto de venta</label>
                    <input class="form-control" name="point_of_sale">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Número</label>
                    <input class="form-control" name="invoice_number" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Neto</label>
                    <input class="form-control" name="net_amount" type="number" step="0.01" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">IVA %</label>
                    <input class="form-control" name="iva_rate" type="number" step="0.01" value="21.00" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">IVA $</label>
                    <input class="form-control" name="iva_amount" type="number" step="0.01" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Total</label>
                    <input class="form-control" name="total_amount" type="number" step="0.01" required>
                </div>
                <div class="col-12">
                    <label class="form-label">Notas</label>
                    <textarea class="form-control" name="notes" rows="2"></textarea>
                </div>
                <div class="col-12">
                    <button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#fiscal-components-collapse" aria-expanded="false" aria-controls="fiscal-components-collapse">
                        Tributos / percepciones sufridas
                    </button>
                    <div class="collapse mt-2" id="fiscal-components-collapse">
                        <div id="fiscal-components-rows" class="d-flex flex-column gap-2"></div>
                        <button type="button" class="btn btn-sm btn-outline-primary mt-2" id="add-fiscal-component">Agregar tributo</button>
                    </div>
                </div>
                <div class="col-12 d-flex gap-2">
                    <button class="btn btn-primary">Guardar compra</button>
                    <a class="btn btn-outline-secondary" href="{{ path('app_purchase_order_edit', {id: order.id}) }}">Volver</a>
                </div>
            </form>
        </div>
    </div>
    <script>
        (() => {
            const rows = document.getElementById('fiscal-components-rows');
            const addBtn = document.getElementById('add-fiscal-component');
            if (!rows || !addBtn) return;
            let idx = 0;
            addBtn.addEventListener('click', () => {
                const row = document.createElement('div');
                row.className = 'border rounded p-2';
                row.innerHTML = `<div class="row g-2"><div class="col-md-3"><select class="form-select form-select-sm" name="fiscal_components[${idx}][componentType]"><option value="OTHER">Otro</option><option value="INTERNAL_TAX">Impuestos internos</option><option value="IIBB_PERCEPTION">Percepción Ingresos Brutos</option><option value="VAT_PERCEPTION">Percepción IVA</option><option value="MUNICIPAL_TAX">Tasa municipal</option><option value="NATIONAL_OTHER_TAX">Otro impuesto nacional</option></select></div><div class="col-md-5"><input class="form-control form-control-sm" name="fiscal_components[${idx}][description]" placeholder="Descripción"></div><div class="col-md-4"><input class="form-control form-control-sm" name="fiscal_components[${idx}][jurisdiction]" placeholder="Jurisdicción"></div><div class="col-md-3"><input class="form-control form-control-sm" name="fiscal_components[${idx}][taxableBase]" placeholder="Base imponible"></div><div class="col-md-2"><input class="form-control form-control-sm" name="fiscal_components[${idx}][rate]" placeholder="Alícuota %"></div><div class="col-md-2"><input class="form-control form-control-sm" name="fiscal_components[${idx}][amount]" placeholder="Importe"></div><div class="col-md-2"><input class="form-control form-control-sm" name="fiscal_components[${idx}][arcaTributeId]" placeholder="Cód. ARCA"></div><div class="col-md-2 d-flex align-items-center"><input type="hidden" name="fiscal_components[${idx}][affectsTotal]" value="0"><input class="form-check-input me-1" type="checkbox" name="fiscal_components[${idx}][affectsTotal]" value="1" checked><label class="form-check-label small">Afecta total</label></div><div class="col-md-3 d-flex align-items-center"><input type="hidden" name="fiscal_components[${idx}][reportToArca]" value="0"><input class="form-check-input me-1" type="checkbox" name="fiscal_components[${idx}][reportToArca]" value="1"><label class="form-check-label small">Informar ARCA</label></div><div class="col-12 text-end"><button type="button" class="btn btn-link btn-sm text-danger remove-row">Quitar</button></div></div>`;
                row.querySelector('.remove-row')?.addEventListener('click', () => row.remove());
                rows.appendChild(row);
                idx++;
            });
        })();
    </script>
{% endblock %}
TWIG;

        return new Response($this->twig->createTemplate($template)->render([
            'order' => $purchaseOrder,
        ]));
    }

    #[Route('/{id}/confirm', name: 'confirm', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function confirm(PurchaseInvoice $purchaseInvoice): Response
    {
        $business = $this->requireBusinessContext();
        $this->denyIfDifferentBusiness($purchaseInvoice, $business);

        if ($purchaseInvoice->getStatus() !== PurchaseInvoice::STATUS_DRAFT) {
            $this->addFlash('warning', 'La compra ya fue confirmada.');
        } else {
            $purchaseInvoice->setStatus(PurchaseInvoice::STATUS_CONFIRMED);
            $purchaseOrder = $purchaseInvoice->getPurchaseOrder();
            if ($purchaseOrder instanceof PurchaseOrder) {
                foreach ($purchaseOrder->getItems() as $item) {
                    $product = $item->getProduct();
                    if ($product !== null) {
                        $product->setPurchasePrice($item->getUnitCost());
                    }
                }
            }
            $this->entityManager->flush();
            $this->addFlash('success', 'Compra confirmada.');
        }

        return $this->redirectToRoute('app_purchase_invoice_index');
    }

    private function hydrateInvoice(PurchaseInvoice $invoice, Request $request, Business $business, Supplier $supplier): PurchaseInvoice
    {
        $invoice->setBusiness($business);
        $invoice->setSupplier($supplier);
        $invoice->setInvoiceType((string) $request->request->get('invoice_type', PurchaseInvoice::TYPE_FACTURA));
        $invoice->setPointOfSale($this->nullify($request->request->get('point_of_sale')));
        $invoice->setInvoiceNumber((string) $request->request->get('invoice_number'));
        $dateInput = (string) $request->request->get('invoice_date');
        $invoice->setInvoiceDate(new \DateTimeImmutable($dateInput));
        $invoice->setNetAmount((string) $request->request->get('net_amount', '0'));
        $invoice->setIvaRate((string) $request->request->get('iva_rate', '0'));
        $invoice->setIvaAmount((string) $request->request->get('iva_amount', '0'));
        $invoice->setTotalAmount((string) $request->request->get('total_amount', '0'));
        $invoice->setNotes($this->nullify($request->request->get('notes')));
        foreach ($invoice->getFiscalComponents()->toArray() as $component) {
            $invoice->removeFiscalComponent($component);
        }
        $fiscalPayload = $request->request->all('fiscal_components');
        if (is_array($fiscalPayload) && $fiscalPayload !== []) {
            $components = $this->fiscalManualComponentFactory->buildForPurchaseInvoice($business, ['fiscal_components' => $fiscalPayload]);
            foreach ($components as $component) {
                if ($component->getSourceType() !== FiscalComponent::SOURCE_PURCHASE_INVOICE) {
                    continue;
                }
                $invoice->addFiscalComponent($component);
            }
        }
        $invoice->recalculateFiscalComponentsTotals();
        $invoice->buildFiscalComponentsSnapshot();
        $expected = bcadd($invoice->getNetAmount(), $invoice->getIvaAmount(), 2);
        $expected = bcadd($expected, $invoice->getFiscalComponentsTotal(), 2);
        $diff = bcsub($invoice->getTotalAmount(), $expected, 2);
        if (bccomp(ltrim($diff, '-'), '0.01', 2) === 1) {
            throw new \InvalidArgumentException('El total de la factura no coincide con neto + IVA + tributos.');
        }

        return $invoice;
    }

    private function requireBusinessContext(): Business
    {
        return $this->businessContext->requireCurrentBusiness();
    }

    private function denyIfDifferentBusiness(PurchaseInvoice|PurchaseOrder $entity, Business $business): void
    {
        if ($entity->getBusiness()?->getId() !== $business->getId()) {
            throw $this->createAccessDeniedException();
        }
    }

    private function nullify(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === '' ? null : $value;
    }
}
