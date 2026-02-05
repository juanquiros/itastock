<?php

namespace App\Form;

use App\Entity\Business;
use App\Entity\Category;
use App\Entity\Discount;
use App\Entity\Product;
use App\Repository\CategoryRepository;
use App\Repository\ProductRepository;
use Doctrine\Common\Collections\Collection;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

class DiscountType extends AbstractType
{
    public function __construct(
        private readonly CategoryRepository $categoryRepository,
        private readonly ProductRepository $productRepository,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nombre',
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'Estado',
                'choices' => [
                    'Activa' => Discount::STATUS_ACTIVE,
                    'Inactiva' => Discount::STATUS_INACTIVE,
                ],
            ])
            ->add('actionType', ChoiceType::class, [
                'label' => 'Tipo de acción',
                'choices' => [
                    'Porcentaje' => Discount::ACTION_PERCENTAGE,
                    'Monto fijo' => Discount::ACTION_FIXED,
                ],
            ])
            ->add('actionValue', NumberType::class, [
                'label' => 'Valor',
                'scale' => 2,
            ])
            ->add('logicOperator', ChoiceType::class, [
                'label' => 'Operador',
                'choices' => [
                    'Cumplir todas (AND)' => Discount::LOGIC_AND,
                    'Cumplir alguna (OR)' => Discount::LOGIC_OR,
                ],
            ])
            ->add('stackable', CheckboxType::class, [
                'label' => 'Permitir acumulación',
                'required' => false,
            ])
            ->add('priority', IntegerType::class, [
                'label' => 'Prioridad',
            ])
            ->add('startAt', DateTimeType::class, [
                'label' => 'Inicio',
                'required' => false,
                'widget' => 'single_text',
            ])
            ->add('endAt', DateTimeType::class, [
                'label' => 'Fin',
                'required' => false,
                'widget' => 'single_text',
            ])
            ->add('paymentMethods', ChoiceType::class, [
                'label' => 'Medios de pago',
                'choices' => [
                    'Efectivo' => 'CASH',
                    'Transferencia' => 'TRANSFER',
                    'Tarjeta' => 'CARD',
                    'Cuenta corriente' => 'ACCOUNT',
                ],
                'mapped' => false,
                'required' => false,
                'expanded' => true,
                'multiple' => true,
            ])
            ->add('minAmount', NumberType::class, [
                'label' => 'Monto mínimo',
                'scale' => 2,
                'mapped' => false,
                'required' => false,
            ])
            ->add('minAmountScope', ChoiceType::class, [
                'label' => 'Aplicar monto mínimo sobre',
                'choices' => [
                    'Orden completa' => 'ORDER',
                    'Monto elegible' => 'ELIGIBLE',
                ],
                'mapped' => false,
                'required' => false,
            ])
            ->add('categories', EntityType::class, [
                'label' => 'Categorías incluidas',
                'class' => Category::class,
                'choice_label' => 'name',
                'query_builder' => fn (CategoryRepository $repo) => $repo->createQueryBuilder('c')
                    ->andWhere('c.business = :business')
                    ->setParameter('business', $options['business'])
                    ->orderBy('c.name', 'ASC'),
                'multiple' => true,
                'required' => false,
                'mapped' => false,
            ])
            ->add('products', EntityType::class, [
                'label' => 'Productos incluidos',
                'class' => Product::class,
                'choice_label' => 'name',
                'query_builder' => fn (ProductRepository $repo) => $repo->createQueryBuilder('p')
                    ->andWhere('p.business = :business')
                    ->andWhere('p.isActive = true')
                    ->setParameter('business', $options['business'])
                    ->orderBy('p.name', 'ASC'),
                'multiple' => true,
                'required' => false,
                'mapped' => false,
            ])
            ->add('excludeCategories', EntityType::class, [
                'label' => 'Categorías excluidas',
                'class' => Category::class,
                'choice_label' => 'name',
                'query_builder' => fn (CategoryRepository $repo) => $repo->createQueryBuilder('c')
                    ->andWhere('c.business = :business')
                    ->setParameter('business', $options['business'])
                    ->orderBy('c.name', 'ASC'),
                'multiple' => true,
                'required' => false,
                'mapped' => false,
            ])
            ->add('excludeProducts', EntityType::class, [
                'label' => 'Productos excluidos',
                'class' => Product::class,
                'choice_label' => 'name',
                'query_builder' => fn (ProductRepository $repo) => $repo->createQueryBuilder('p')
                    ->andWhere('p.business = :business')
                    ->andWhere('p.isActive = true')
                    ->setParameter('business', $options['business'])
                    ->orderBy('p.name', 'ASC'),
                'multiple' => true,
                'required' => false,
                'mapped' => false,
            ])
            ->add('daysOfWeek', ChoiceType::class, [
                'label' => 'Días de la semana',
                'choices' => [
                    'Lunes' => 1,
                    'Martes' => 2,
                    'Miércoles' => 3,
                    'Jueves' => 4,
                    'Viernes' => 5,
                    'Sábado' => 6,
                    'Domingo' => 7,
                ],
                'mapped' => false,
                'required' => false,
                'expanded' => true,
                'multiple' => true,
            ])
            ->add('hoursStart', TimeType::class, [
                'label' => 'Hora desde',
                'mapped' => false,
                'required' => false,
                'widget' => 'single_text',
                'input' => 'string',
            ])
            ->add('hoursEnd', TimeType::class, [
                'label' => 'Hora hasta',
                'mapped' => false,
                'required' => false,
                'widget' => 'single_text',
                'input' => 'string',
            ]);

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) use ($options) {
            $discount = $event->getData();
            if (!$discount instanceof Discount) {
                return;
            }

            $conditions = $discount->getConditions();
            $form = $event->getForm();
            $business = $options['business'];

            $form->get('paymentMethods')->setData($conditions['payment_methods'] ?? []);
            $form->get('minAmount')->setData($conditions['min_amount'] ?? null);
            $form->get('minAmountScope')->setData($conditions['min_amount_scope'] ?? null);
            $form->get('daysOfWeek')->setData($conditions['days_of_week'] ?? []);
            $form->get('hoursStart')->setData($conditions['hours']['start'] ?? null);
            $form->get('hoursEnd')->setData($conditions['hours']['end'] ?? null);

            $form->get('categories')->setData($this->resolveCategories($conditions['categories'] ?? [], $business));
            $form->get('products')->setData($this->resolveProducts($conditions['products'] ?? [], $business));
            $form->get('excludeCategories')->setData($this->resolveCategories($conditions['exclude_categories'] ?? [], $business));
            $form->get('excludeProducts')->setData($this->resolveProducts($conditions['exclude_products'] ?? [], $business));
        });

        $builder->addEventListener(FormEvents::SUBMIT, function (FormEvent $event): void {
            $discount = $event->getData();
            if (!$discount instanceof Discount) {
                return;
            }

            $form = $event->getForm();
            $conditions = [];

            $paymentMethods = (array) $form->get('paymentMethods')->getData();
            if ($paymentMethods !== []) {
                $conditions['payment_methods'] = $paymentMethods;
            }

            $minAmount = $form->get('minAmount')->getData();
            if ($minAmount !== null && $minAmount !== '') {
                $conditions['min_amount'] = number_format((float) $minAmount, 2, '.', '');
                $scope = $form->get('minAmountScope')->getData();
                if ($scope) {
                    $conditions['min_amount_scope'] = $scope;
                }
            }

            $categories = $form->get('categories')->getData();
            if ($categories) {
                $categoryItems = $categories instanceof Collection ? $categories->toArray() : (array) $categories;
                $conditions['categories'] = array_map(static fn (Category $category) => $category->getId(), $categoryItems);
            }

            $products = $form->get('products')->getData();
            if ($products) {
                $productItems = $products instanceof Collection ? $products->toArray() : (array) $products;
                $conditions['products'] = array_map(static fn (Product $product) => $product->getId(), $productItems);
            }

            $excludeCategories = $form->get('excludeCategories')->getData();
            if ($excludeCategories) {
                $excludeCategoryItems = $excludeCategories instanceof Collection ? $excludeCategories->toArray() : (array) $excludeCategories;
                $conditions['exclude_categories'] = array_map(static fn (Category $category) => $category->getId(), $excludeCategoryItems);
            }

            $excludeProducts = $form->get('excludeProducts')->getData();
            if ($excludeProducts) {
                $excludeProductItems = $excludeProducts instanceof Collection ? $excludeProducts->toArray() : (array) $excludeProducts;
                $conditions['exclude_products'] = array_map(static fn (Product $product) => $product->getId(), $excludeProductItems);
            }

            $days = (array) $form->get('daysOfWeek')->getData();
            if ($days !== []) {
                $conditions['days_of_week'] = array_map('intval', $days);
            }

            $hoursStart = $form->get('hoursStart')->getData();
            $hoursEnd = $form->get('hoursEnd')->getData();
            if ($hoursStart || $hoursEnd) {
                $conditions['hours'] = [
                    'start' => $hoursStart ?: null,
                    'end' => $hoursEnd ?: null,
                ];
            }

            $discount->setConditions($conditions);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Discount::class,
            'business' => null,
        ]);

        $resolver->setAllowedTypes('business', ['null', Business::class]);
    }

    /**
     * @param array<int, int|string> $ids
     * @return array<int, Category>
     */
    private function resolveCategories(array $ids, ?Business $business): array
    {
        if ($ids === [] || $business === null) {
            return [];
        }

        return $this->categoryRepository->findBy([
            'id' => $ids,
            'business' => $business,
        ]);
    }

    /**
     * @param array<int, int|string> $ids
     * @return array<int, Product>
     */
    private function resolveProducts(array $ids, ?Business $business): array
    {
        if ($ids === [] || $business === null) {
            return [];
        }

        return $this->productRepository->findBy([
            'id' => $ids,
            'business' => $business,
        ]);
    }
}
