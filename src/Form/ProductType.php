<?php

namespace App\Form;

use App\Entity\Brand;
use App\Entity\Category;
use App\Entity\Product;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ProductType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
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
        });

        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event): void {
            $data = $event->getData();
            if (!is_array($data)) {
                return;
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
}
