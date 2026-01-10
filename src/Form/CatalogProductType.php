<?php

namespace App\Form;

use App\Entity\CatalogBrand;
use App\Entity\CatalogCategory;
use App\Entity\CatalogProduct;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CatalogProductType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nombre',
            ])
            ->add('presentation', TextType::class, [
                'label' => 'Presentación',
                'required' => false,
            ])
            ->add('barcode', TextType::class, [
                'label' => 'Código de barras',
                'required' => false,
            ])
            ->add('sku', TextType::class, [
                'label' => 'SKU',
                'required' => false,
            ])
            ->add('category', EntityType::class, [
                'label' => 'Categoría global',
                'class' => CatalogCategory::class,
                'query_builder' => static function (EntityRepository $er) {
                    return $er->createQueryBuilder('c')
                        ->orderBy('c.name', 'ASC');
                },
            ])
            ->add('brand', EntityType::class, [
                'label' => 'Marca global',
                'class' => CatalogBrand::class,
                'required' => false,
                'placeholder' => 'Sin marca',
                'query_builder' => static function (EntityRepository $er) {
                    return $er->createQueryBuilder('b')
                        ->orderBy('b.name', 'ASC');
                },
            ])
            ->add('isActive', CheckboxType::class, [
                'label' => 'Activo',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CatalogProduct::class,
        ]);
    }
}
