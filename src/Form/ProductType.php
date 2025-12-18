<?php

namespace App\Form;

use App\Entity\Category;
use App\Entity\Product;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ProductType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nombre',
            ])
            ->add('sku', TextType::class, [
                'label' => 'SKU',
            ])
            ->add('barcode', TextType::class, [
                'label' => 'Código de barras',
                'required' => false,
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
            ->add('stockMin', IntegerType::class, [
                'label' => 'Stock mínimo',
            ])
            ->add('category', EntityType::class, [
                'label' => 'Categoría',
                'class' => Category::class,
                'placeholder' => 'Sin categoría',
                'required' => false,
                'query_builder' => function (EntityRepository $er) use ($options) {
                    return $er->createQueryBuilder('c')
                        ->where('c.business = :business')
                        ->setParameter('business', $options['current_business'])
                        ->orderBy('c.name', 'ASC');
                },
            ])
            ->add('isActive', CheckboxType::class, [
                'label' => 'Activo',
                'required' => false,
            ])
            ->add('stockAdjustment', IntegerType::class, [
                'label' => 'Ajuste de stock (± unidades)',
                'mapped' => false,
                'required' => false,
                'data' => 0,
                'help' => 'Usá valores positivos para sumar y negativos para restar stock.',
            ]);

        if ($options['show_stock']) {
            $builder->add('stock', IntegerType::class, [
                'label' => 'Stock actual',
                'mapped' => false,
                'disabled' => true,
                'data' => $options['current_stock'],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Product::class,
            'current_business' => null,
            'show_stock' => false,
            'current_stock' => 0,
        ]);
    }
}
