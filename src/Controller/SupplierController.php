<?php

namespace App\Controller;

use App\Entity\Business;
use App\Entity\Supplier;
use App\Security\BusinessContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;

#[IsGranted('BUSINESS_ADMIN')]
#[Route('/app/admin/suppliers', name: 'app_supplier_')]
class SupplierController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BusinessContext $businessContext,
        private readonly Environment $twig,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $business = $this->requireBusinessContext();

        if ($request->isMethod('POST')) {
            $name = trim((string) $request->request->get('name'));
            if ($name === '') {
                $this->addFlash('danger', 'El nombre es obligatorio.');
            } else {
                $supplier = new Supplier();
                $supplier->setBusiness($business);
                $supplier->setName($name);
                $supplier->setCuit($this->nullify($request->request->get('cuit')));
                $supplier->setIvaCondition((string) $request->request->get('ivaCondition', Supplier::IVA_RI));
                $supplier->setEmail($this->nullify($request->request->get('email')));
                $supplier->setPhone($this->nullify($request->request->get('phone')));
                $supplier->setAddress($this->nullify($request->request->get('address')));
                $supplier->setNotes($this->nullify($request->request->get('notes')));
                $supplier->setActive($request->request->getBoolean('active'));
                $this->entityManager->persist($supplier);
                $this->entityManager->flush();

                $this->addFlash('success', 'Proveedor creado.');

                return $this->redirectToRoute('app_supplier_index');
            }
        }

        $suppliers = $this->entityManager->getRepository(Supplier::class)
            ->findBy(['business' => $business], ['name' => 'ASC']);

        $template = <<<'TWIG'
{% extends 'base.html.twig' %}

{% block title %}Proveedores · ItaStock{% endblock %}

{% block body %}
    <div class="mb-4">
        <p class="text-uppercase text-muted mb-1 small fw-semibold">Administración</p>
        <h1 class="h4 mb-0">Proveedores</h1>
        <p class="text-secondary mb-0">Gestioná proveedores y datos fiscales para compras.</p>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <h2 class="h6 fw-semibold">Nuevo proveedor</h2>
            <form method="post" class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Nombre o razón social</label>
                    <input class="form-control" name="name" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">CUIT</label>
                    <input class="form-control" name="cuit">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Condición IVA</label>
                    <select class="form-select" name="ivaCondition">
                        <option value="MONOTRIBUTO">Monotributo</option>
                        <option value="RI" selected>Responsable inscripto</option>
                        <option value="EXENTO">Exento</option>
                        <option value="CF">Consumidor final</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Email</label>
                    <input class="form-control" name="email" type="email">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Teléfono</label>
                    <input class="form-control" name="phone">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Dirección</label>
                    <input class="form-control" name="address">
                </div>
                <div class="col-12">
                    <label class="form-label">Notas</label>
                    <textarea class="form-control" name="notes" rows="2"></textarea>
                </div>
                <div class="col-12 form-check">
                    <input class="form-check-input" type="checkbox" name="active" id="active" checked>
                    <label class="form-check-label" for="active">Proveedor activo</label>
                </div>
                <div class="col-12">
                    <button class="btn btn-primary">Guardar proveedor</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
            <h2 class="h6 fw-semibold">Listado</h2>
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr>
                            <th>Proveedor</th>
                            <th>CUIT</th>
                            <th>IVA</th>
                            <th>Email</th>
                            <th>Teléfono</th>
                            <th>Estado</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        {% for supplier in suppliers %}
                            <tr>
                                <td>{{ supplier.name }}</td>
                                <td>{{ supplier.cuit ?: '-' }}</td>
                                <td>{{ supplier.ivaCondition }}</td>
                                <td>{{ supplier.email ?: '-' }}</td>
                                <td>{{ supplier.phone ?: '-' }}</td>
                                <td>
                                    {% if supplier.active %}
                                        <span class="badge bg-success">Activo</span>
                                    {% else %}
                                        <span class="badge bg-secondary">Inactivo</span>
                                    {% endif %}
                                </td>
                                <td class="text-end">
                                    <a class="btn btn-outline-secondary btn-sm" href="{{ path('app_supplier_edit', {id: supplier.id}) }}">Editar</a>
                                </td>
                            </tr>
                        {% else %}
                            <tr>
                                <td colspan="7" class="text-muted">No hay proveedores cargados.</td>
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
            'suppliers' => $suppliers,
        ]));
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, Supplier $supplier): Response
    {
        $business = $this->requireBusinessContext();
        $this->denyIfDifferentBusiness($supplier, $business);

        if ($request->isMethod('POST')) {
            $name = trim((string) $request->request->get('name'));
            if ($name === '') {
                $this->addFlash('danger', 'El nombre es obligatorio.');
            } else {
                $supplier->setName($name);
                $supplier->setCuit($this->nullify($request->request->get('cuit')));
                $supplier->setIvaCondition((string) $request->request->get('ivaCondition', Supplier::IVA_RI));
                $supplier->setEmail($this->nullify($request->request->get('email')));
                $supplier->setPhone($this->nullify($request->request->get('phone')));
                $supplier->setAddress($this->nullify($request->request->get('address')));
                $supplier->setNotes($this->nullify($request->request->get('notes')));
                $supplier->setActive($request->request->getBoolean('active'));
                $this->entityManager->flush();

                $this->addFlash('success', 'Proveedor actualizado.');

                return $this->redirectToRoute('app_supplier_index');
            }
        }

        $template = <<<'TWIG'
{% extends 'base.html.twig' %}

{% block title %}Editar proveedor · ItaStock{% endblock %}

{% block body %}
    <div class="mb-4">
        <p class="text-uppercase text-muted mb-1 small fw-semibold">Administración</p>
        <h1 class="h4 mb-0">Editar proveedor</h1>
        <p class="text-secondary mb-0">Actualizá datos de contacto y fiscales.</p>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
            <form method="post" class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Nombre o razón social</label>
                    <input class="form-control" name="name" value="{{ supplier.name }}" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">CUIT</label>
                    <input class="form-control" name="cuit" value="{{ supplier.cuit }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Condición IVA</label>
                    <select class="form-select" name="ivaCondition">
                        {% set iva = supplier.ivaCondition %}
                        <option value="MONOTRIBUTO" {{ iva == 'MONOTRIBUTO' ? 'selected' }}>Monotributo</option>
                        <option value="RI" {{ iva == 'RI' ? 'selected' }}>Responsable inscripto</option>
                        <option value="EXENTO" {{ iva == 'EXENTO' ? 'selected' }}>Exento</option>
                        <option value="CF" {{ iva == 'CF' ? 'selected' }}>Consumidor final</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Email</label>
                    <input class="form-control" name="email" type="email" value="{{ supplier.email }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Teléfono</label>
                    <input class="form-control" name="phone" value="{{ supplier.phone }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Dirección</label>
                    <input class="form-control" name="address" value="{{ supplier.address }}">
                </div>
                <div class="col-12">
                    <label class="form-label">Notas</label>
                    <textarea class="form-control" name="notes" rows="2">{{ supplier.notes }}</textarea>
                </div>
                <div class="col-12 form-check">
                    <input class="form-check-input" type="checkbox" name="active" id="active" {{ supplier.active ? 'checked' }}>
                    <label class="form-check-label" for="active">Proveedor activo</label>
                </div>
                <div class="col-12 d-flex gap-2">
                    <button class="btn btn-primary">Guardar cambios</button>
                    <a class="btn btn-outline-secondary" href="{{ path('app_supplier_index') }}">Volver</a>
                </div>
            </form>
        </div>
    </div>
{% endblock %}
TWIG;

        return new Response($this->twig->createTemplate($template)->render([
            'supplier' => $supplier,
        ]));
    }

    private function requireBusinessContext(): Business
    {
        return $this->businessContext->requireCurrentBusiness();
    }

    private function denyIfDifferentBusiness(Supplier $supplier, Business $business): void
    {
        if ($supplier->getBusiness()?->getId() !== $business->getId()) {
            throw $this->createAccessDeniedException();
        }
    }

    private function nullify(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === '' ? null : $value;
    }
}
