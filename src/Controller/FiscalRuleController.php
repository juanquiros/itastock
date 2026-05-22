<?php

namespace App\Controller;

use App\Entity\Business;
use App\Entity\Category;
use App\Entity\Customer;
use App\Entity\FiscalComponent;
use App\Entity\FiscalRule;
use App\Entity\Product;
use App\Repository\CategoryRepository;
use App\Repository\CustomerRepository;
use App\Repository\FiscalRuleRepository;
use App\Repository\ProductRepository;
use App\Security\BusinessContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/app/admin/fiscal-rules', name: 'app_fiscal_rule_')]
#[IsGranted('BUSINESS_ADMIN')]
class FiscalRuleController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BusinessContext $businessContext,
        private readonly ProductRepository $productRepository,
        private readonly CategoryRepository $categoryRepository,
        private readonly CustomerRepository $customerRepository,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(FiscalRuleRepository $repository): Response
    {
        $business = $this->businessContext->requireCurrentBusiness();

        return $this->render('fiscal_rule/index.html.twig', [
            'rules' => $repository->findForAdminList($business),
            'componentTypeLabels' => $this->componentTypeLabels(),
            'appliesToLabels' => $this->appliesToLabels(),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $business = $this->businessContext->requireCurrentBusiness();
        $rule = (new FiscalRule())->setBusiness($business);

        return $this->upsert($request, $rule, $business);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, FiscalRule $rule): Response
    {
        $business = $this->businessContext->requireCurrentBusiness();
        $this->assertRuleBelongsToBusiness($rule, $business);

        return $this->upsert($request, $rule, $business);
    }

    #[Route('/{id}/toggle', name: 'toggle', methods: ['POST'])]
    public function toggle(FiscalRule $rule): Response
    {
        $business = $this->businessContext->requireCurrentBusiness();
        $this->assertRuleBelongsToBusiness($rule, $business);
        $rule->setActive(!$rule->isActive());
        $this->em->flush();

        return $this->redirectToRoute('app_fiscal_rule_index');
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(FiscalRule $rule): Response
    {
        $business = $this->businessContext->requireCurrentBusiness();
        $this->assertRuleBelongsToBusiness($rule, $business);
        $this->em->remove($rule);
        $this->em->flush();

        return $this->redirectToRoute('app_fiscal_rule_index');
    }

    private function upsert(Request $request, FiscalRule $rule, Business $business): Response
    {
        $products = $this->productRepository->findBy(['business' => $business, 'isActive' => true], ['name' => 'ASC']);
        $categories = $this->categoryRepository->findBy(['business' => $business], ['name' => 'ASC']);
        $customers = $this->customerRepository->findBy(['business' => $business], ['name' => 'ASC']);

        if ($request->isMethod('POST')) {
            try {
                $this->hydrateRule($rule, $request, $business);
                $errors = $this->validateRule($rule);
                if ($errors !== []) {
                    foreach ($errors as $error) {
                        $this->addFlash('danger', $error);
                    }
                } else {
                    $this->em->persist($rule);
                    $this->em->flush();
                    $this->addFlash('success', 'Regla fiscal guardada.');
                    return $this->redirectToRoute('app_fiscal_rule_index');
                }
            } catch (\Throwable $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        }

        return $this->render('fiscal_rule/form.html.twig', [
            'rule' => $rule,
            'products' => $products,
            'categories' => $categories,
            'customers' => $customers,
            'componentTypeLabels' => $this->componentTypeLabels(),
            'appliesToLabels' => $this->appliesToLabels(),
            'taxableBaseModeLabels' => $this->taxableBaseModeLabels(),
            'ivaConditionOptions' => [
                1 => 'Responsable Inscripto', 5 => 'Consumidor Final', 6 => 'Responsable Monotributo', 4 => 'IVA Sujeto Exento',
            ],
        ]);
    }

    private function hydrateRule(FiscalRule $rule, Request $request, Business $business): void
    {
        $d = $request->request;
        $rule->setName((string) $this->emptyToNull($d->get('name')))
            ->setActive($this->parseBool($d->get('active')))
            ->setPriority((int) ($d->get('priority', 100)))
            ->setComponentType((string) $d->get('componentType', FiscalComponent::TYPE_OTHER))
            ->setAppliesTo((string) $d->get('appliesTo', FiscalRule::APPLIES_TO_GLOBAL))
            ->setJurisdiction($this->emptyToNull($d->get('jurisdiction')))
            ->setDescriptionTemplate($this->emptyToNull($d->get('descriptionTemplate')))
            ->setTaxableBaseMode((string) $d->get('taxableBaseMode', FiscalRule::TAXABLE_BASE_SALE_NET))
            ->setRate($this->decimalOrNull($d->get('rate'), 4))
            ->setFixedAmount($this->decimalOrNull($d->get('fixedAmount'), 2))
            ->setMinAmount($this->decimalOrNull($d->get('minAmount'), 2))
            ->setMaxAmount($this->decimalOrNull($d->get('maxAmount'), 2))
            ->setArcaTributeId($this->emptyToNull($d->get('arcaTributeId')) !== null ? (int) $d->get('arcaTributeId') : null)
            ->setReportToArca($this->parseBool($d->get('reportToArca')))
            ->setAffectsTotal($this->parseBool($d->get('affectsTotal')))
            ->setIncludedInPrice($this->parseBool($d->get('includedInPrice')))
            ->setStopProcessing($this->parseBool($d->get('stopProcessing')))
            ->setStartsAt($this->dateOrNull($d->get('startsAt')))
            ->setEndsAt($this->dateOrNull($d->get('endsAt')));

        $rule->setProduct(null)->setCategory(null)->setCustomer(null)->setCustomerIvaConditionId(null);
        $appliesTo = $rule->getAppliesTo();
        if ($appliesTo === FiscalRule::APPLIES_TO_PRODUCT) {
            $product = $this->productRepository->find((int) $d->get('productId', 0));
            if (!$product instanceof Product || $product->getBusiness()?->getId() !== $business->getId()) {
                throw new AccessDeniedException('Producto inválido para este comercio.');
            }
            $rule->setProduct($product);
        } elseif ($appliesTo === FiscalRule::APPLIES_TO_CATEGORY) {
            $category = $this->categoryRepository->find((int) $d->get('categoryId', 0));
            if (!$category instanceof Category || $category->getBusiness()?->getId() !== $business->getId()) {
                throw new AccessDeniedException('Categoría inválida para este comercio.');
            }
            $rule->setCategory($category);
        } elseif ($appliesTo === FiscalRule::APPLIES_TO_CUSTOMER) {
            $customer = $this->customerRepository->find((int) $d->get('customerId', 0));
            if (!$customer instanceof Customer || $customer->getBusiness()?->getId() !== $business->getId()) {
                throw new AccessDeniedException('Cliente inválido para este comercio.');
            }
            $rule->setCustomer($customer);
        } elseif ($appliesTo === FiscalRule::APPLIES_TO_CUSTOMER_IVA_CONDITION) {
            $value = $this->emptyToNull($d->get('customerIvaConditionId'));
            $rule->setCustomerIvaConditionId($value !== null ? (int) $value : null);
        }
    }

    private function validateRule(FiscalRule $rule): array
    {
        $errors = [];
        if (trim($rule->getName()) === '') $errors[] = 'El nombre es obligatorio.';
        if (!array_key_exists($rule->getComponentType(), $this->componentTypeLabels())) $errors[] = 'Tipo de componente inválido.';
        if (!array_key_exists($rule->getAppliesTo(), $this->appliesToLabels())) $errors[] = 'Aplica a inválido.';
        if (!array_key_exists($rule->getTaxableBaseMode(), $this->taxableBaseModeLabels())) $errors[] = 'Base de cálculo inválida.';
        if ($rule->getRate() === null && $rule->getFixedAmount() === null) $errors[] = 'Indicá alícuota o monto fijo.';
        if ($rule->getStartsAt() && $rule->getEndsAt() && $rule->getStartsAt() > $rule->getEndsAt()) $errors[] = 'La vigencia desde no puede ser mayor a hasta.';
        if ($rule->getAppliesTo() === FiscalRule::APPLIES_TO_PRODUCT && !$rule->getProduct()) $errors[] = 'Seleccioná un producto.';
        if ($rule->getAppliesTo() === FiscalRule::APPLIES_TO_CATEGORY && !$rule->getCategory()) $errors[] = 'Seleccioná una categoría.';
        if ($rule->getAppliesTo() === FiscalRule::APPLIES_TO_CUSTOMER && !$rule->getCustomer()) $errors[] = 'Seleccioná un cliente.';
        if ($rule->getAppliesTo() === FiscalRule::APPLIES_TO_CUSTOMER_IVA_CONDITION && $rule->getCustomerIvaConditionId() === null) $errors[] = 'Seleccioná condición IVA.';
        return $errors;
    }

    private function assertRuleBelongsToBusiness(FiscalRule $rule, Business $business): void
    {
        if ($rule->getBusiness()?->getId() !== $business->getId()) throw new AccessDeniedException('No tenés acceso a esta regla fiscal.');
    }
    private function parseBool(mixed $value): bool { return in_array($value, ['1', 1, true, 'true', 'on', 'yes', 'si', 'sí'], true); }
    private function emptyToNull(mixed $value): ?string { $value = is_string($value) ? trim($value) : $value; return $value === '' || $value === null ? null : (string) $value; }
    private function decimalOrNull(mixed $value, int $scale): ?string { $value = $this->emptyToNull($value); if ($value===null) return null; $value=str_replace(',', '.', $value); if (!is_numeric($value) || (float)$value<0) throw new \InvalidArgumentException('Importe o alícuota inválida.'); return number_format((float)$value, $scale, '.', ''); }
    private function dateOrNull(mixed $value): ?\DateTimeInterface { $v=$this->emptyToNull($value); return $v ? new \DateTimeImmutable($v) : null; }
    private function componentTypeLabels(): array { return [FiscalComponent::TYPE_INTERNAL_TAX=>'Impuestos internos',FiscalComponent::TYPE_IIBB_PERCEPTION=>'Percepción Ingresos Brutos',FiscalComponent::TYPE_VAT_PERCEPTION=>'Percepción IVA',FiscalComponent::TYPE_MUNICIPAL_TAX=>'Tasa municipal',FiscalComponent::TYPE_NATIONAL_OTHER_TAX=>'Otro impuesto nacional',FiscalComponent::TYPE_OTHER=>'Otro']; }
    private function appliesToLabels(): array { return [FiscalRule::APPLIES_TO_GLOBAL=>'Global',FiscalRule::APPLIES_TO_PRODUCT=>'Producto',FiscalRule::APPLIES_TO_CATEGORY=>'Categoría',FiscalRule::APPLIES_TO_CUSTOMER=>'Cliente',FiscalRule::APPLIES_TO_CUSTOMER_IVA_CONDITION=>'Condición IVA cliente']; }
    private function taxableBaseModeLabels(): array { return [FiscalRule::TAXABLE_BASE_SALE_NET=>'Neto de venta',FiscalRule::TAXABLE_BASE_SALE_TOTAL=>'Total de venta',FiscalRule::TAXABLE_BASE_ITEM_NET=>'Neto de ítems alcanzados',FiscalRule::TAXABLE_BASE_MANUAL_BASE=>'Base manual / monto fijo']; }
}
