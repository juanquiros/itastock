<?php

namespace App\Form;

use App\Entity\Brand;
use App\Entity\Category;
use App\Entity\Product;
use App\Entity\Supplier;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ProductType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $submittedCharacteristics = [];

        $builder
            ->add('catalogProductId', HiddenType::class, [
                'mapped' => false,
                'data' => $options['catalog_product_id'],
            ])
            ->add('name', TextType::class, [
                'label' => 'Nombre',
                'attr' => [
                    'data-catalog-lookup-target' => 'name',
                    'autocomplete' => 'off',
                ],
            ])
            ->add('sku', TextType::class, [
                'label' => 'SKU',
                'help' => 'Se genera automáticamente, podés modificarlo.',
            ])
            ->add('barcode', TextType::class, [
                'label' => 'Código de barras',
                'required' => false,
                'attr' => [
                    'data-catalog-lookup-target' => 'barcode',
                ],
            ])
            ->add('cost', NumberType::class, [
                'label' => 'Costo',
                'scale' => 2,
                'html5' => true,
                'attr' => ['step' => '0.01'],
            ])
            ->add('basePrice', NumberType::class, [
                'label' => 'Precio base',
                'scale' => 2,
                'html5' => true,
                'attr' => ['step' => '0.01'],
            ])
            ->add('purchasePrice', NumberType::class, [
                'label' => 'Precio de compra',
                'required' => false,
                'scale' => 2,
                'html5' => true,
                'attr' => ['step' => '0.01'],
                'help' => 'Costo',
            ])
            ->add('ivaRate', TextType::class, [
                'label' => 'IVA (%)',
                'required' => false,
                'help' => 'Si se define, pisa el de la categoría. Ej: 21, 10.5, 0',
            ])
            ->add('supplier', EntityType::class, [
                'label' => 'Proveedor',
                'class' => Supplier::class,
                'choice_label' => 'name',
                'placeholder' => 'Sin proveedor',
                'required' => false,
                'query_builder' => function (EntityRepository $er) use ($options) {
                    return $er->createQueryBuilder('s')
                        ->where('s.business = :business')
                        ->setParameter('business', $options['current_business'])
                        ->orderBy('s.name', 'ASC');
                },
            ])
            ->add('supplierSku', TextType::class, [
                'label' => 'Código proveedor',
                'required' => false,
            ])
            ->add('uomBase', ChoiceType::class, [
                'label' => 'Unidad de medida',
                'choices' => [
                    'Unidad' => Product::UOM_UNIT,
                    'Kilogramo' => Product::UOM_KG,
                    'Gramo' => Product::UOM_G,
                    'Litro' => Product::UOM_L,
                    'Mililitro' => Product::UOM_ML,
                ],
                'placeholder' => false,
            ])
            ->add('allowsFractionalQty', CheckboxType::class, [
                'label' => 'Permitir fraccionar cantidad',
                'required' => false,
            ])
            ->add('qtyStep', NumberType::class, [
                'label' => 'Paso de cantidad (ej. 0.1 kg)',
                'scale' => 3,
                'required' => false,
                'html5' => true,
                'attr' => ['step' => '0.001', 'min' => '0.001'],
                'help' => 'Se usa como step mínimo en POS cuando el producto admite fraccionar.',
            ])
            ->add('stockMin', NumberType::class, [
                'label' => 'Stock mínimo',
                'scale' => 3,
                'html5' => true,
                'attr' => ['step' => '0.001', 'min' => '0'],
                'help' => 'Permite decimales para productos fraccionables (ej. 0.250 kg).',
            ])
            ->add('category', EntityType::class, [
                'label' => 'Categoría',
                'class' => Category::class,
                'placeholder' => 'Sin categoría',
                'required' => false,
                'attr' => [
                    'data-catalog-lookup-target' => 'category',
                ],
                'query_builder' => function (EntityRepository $er) use ($options) {
                    return $er->createQueryBuilder('c')
                        ->where('c.business = :business')
                        ->setParameter('business', $options['current_business'])
                        ->orderBy('c.name', 'ASC');
                },
            ])
            ->add('brand', EntityType::class, [
                'label' => 'Marca',
                'class' => Brand::class,
                'placeholder' => 'Sin marca',
                'required' => false,
                'attr' => [
                    'data-catalog-lookup-target' => 'brand',
                ],
                'query_builder' => function (EntityRepository $er) use ($options) {
                    return $er->createQueryBuilder('b')
                        ->where('b.business = :business')
                        ->setParameter('business', $options['current_business'])
                        ->orderBy('b.name', 'ASC');
                },
            ])
            ->add('isActive', CheckboxType::class, [
                'label' => 'Activo',
                'required' => false,
            ])
            ->add('characteristics', CollectionType::class, $this->characteristicsFieldOptions())
            ->add('stockAdjustment', NumberType::class, [
                'label' => 'Ajuste de stock (± unidades)',
                'mapped' => false,
                'required' => false,
                'data' => '0.000',
                'help' => 'Usá valores positivos para sumar y negativos para restar stock.',
                'scale' => 3,
                'html5' => true,
                'attr' => ['step' => '0.001'],
            ]);

        if ($options['show_stock']) {
            $builder->add('stock', NumberType::class, [
                'label' => 'Stock actual',
                'mapped' => false,
                'disabled' => true,
                'data' => $options['current_stock'],
                'scale' => 3,
            ]);
        }

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
            $product = $event->getData();

            if (!$product instanceof Product) {
                return;
            }

            if ($product->getUomBase() !== Product::UOM_UNIT && $product->getQtyStep() === null) {
                $product->setAllowsFractionalQty(true);
                $product->setQtyStep('0.001');
            }

            $characteristics = [];
            foreach ($product->getCharacteristics() as $key => $value) {
                $characteristics[] = ['key' => $key, 'value' => $value];
            }

            $form = $event->getForm();
            $form->remove('characteristics');
            $form->add('characteristics', CollectionType::class, $this->characteristicsFieldOptions($characteristics));
        });

        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) use (&$submittedCharacteristics): void {
            $data = $event->getData();
            if (!is_array($data)) {
                return;
            }

            if (array_key_exists('ivaRate', $data)) {
                $normalized = $this->normalizeRate($data['ivaRate']);
                if ($normalized === null && $data['ivaRate'] !== null && $data['ivaRate'] !== '') {
                    $event->getForm()->get('ivaRate')->addError(new FormError('Ingresá un IVA válido (solo números y coma/punto).'));
                }
                $data['ivaRate'] = $normalized;
            }

            $submittedCharacteristics = [];
            if (isset($data['characteristics']) && is_array($data['characteristics'])) {
                $keys = [];
                foreach ($data['characteristics'] as $index => $row) {
                    if (!is_array($row)) {
                        continue;
                    }

                    $key = trim((string) ($row['key'] ?? ''));
                    $value = trim((string) ($row['value'] ?? ''));

                    if (($key === '' && $value !== '') || ($key !== '' && $value === '')) {
                        $event->getForm()->get('characteristics')->addError(new FormError('Cada característica debe tener clave y valor.'));
                        continue;
                    }

                    if ($key === '' && $value === '') {
                        unset($data['characteristics'][$index]);
                        continue;
                    }

                    $normalized = mb_strtolower($key);
                    if (isset($keys[$normalized])) {
                        $event->getForm()->get('characteristics')->addError(new FormError('No se permiten claves de características duplicadas.'));
                        continue;
                    }

                    $keys[$normalized] = true;
                    $data['characteristics'][$index]['key'] = $key;
                    $data['characteristics'][$index]['value'] = $value;
                    $submittedCharacteristics[$key] = $value;
                }
                $data['characteristics'] = array_values($data['characteristics']);
            }

            $uom = $data['uomBase'] ?? Product::UOM_UNIT;
            if ($uom === Product::UOM_UNIT) {
                $data['allowsFractionalQty'] = false;
                $data['qtyStep'] = null;
            } else {
                if (!isset($data['allowsFractionalQty'])) {
                    $data['allowsFractionalQty'] = true;
                }

                if (($data['qtyStep'] ?? '') === '' || $data['qtyStep'] === null) {
                    $data['qtyStep'] = '0.001';
                }
            }

            $event->setData($data);
        });

        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event) use ($options, &$submittedCharacteristics): void {
            $product = $event->getData();

            if (!$product instanceof Product) {
                return;
            }

            $product->setCharacteristics($submittedCharacteristics);

            $supplier = $product->getSupplier();
            $business = $options['current_business'];
            if ($supplier !== null && $business !== null && $supplier->getBusiness()?->getId() !== $business->getId()) {
                $event->getForm()->get('supplier')->addError(new FormError('Proveedor inválido.'));
            }
        });
    }

    /**
     * @param array<int, array{key: string, value: string}> $data
     *
     * @return array<string, mixed>
     */
    private function characteristicsFieldOptions(array $data = []): array
    {
        return [
            'label' => false,
            'mapped' => false,
            'required' => false,
            'entry_type' => ProductCharacteristicType::class,
            'allow_add' => true,
            'allow_delete' => true,
            'prototype' => true,
            'by_reference' => false,
            'data' => $data,
        ];
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Product::class,
            'current_business' => null,
            'show_stock' => false,
            'current_stock' => '0.000',
            'catalog_product_id' => null,
        ]);
    }

    private function normalizeRate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $raw = str_replace(',', '.', trim((string) $value));
        if ($raw === '' || !is_numeric($raw)) {
            return null;
        }

        if ((float) $raw < 0) {
            return null;
        }

        return bcadd($raw, '0', 2);
    }
}
