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
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ProductLabelFilterType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('products', EntityType::class, [
                'label' => 'Productos específicos',
                'class' => Product::class,
                'choice_label' => 'name',
                'multiple' => true,
                'required' => false,
                'query_builder' => function (EntityRepository $er) use ($options) {
                    return $er->createQueryBuilder('p')
                        ->where('p.business = :business')
                        ->setParameter('business', $options['current_business'])
                        ->orderBy('p.name', 'ASC');
                },
            ])
            ->add('categories', EntityType::class, [
                'label' => 'Categorías',
                'class' => Category::class,
                'choice_label' => 'name',
                'multiple' => true,
                'required' => false,
                'query_builder' => function (EntityRepository $er) use ($options) {
                    return $er->createQueryBuilder('c')
                        ->where('c.business = :business')
                        ->setParameter('business', $options['current_business'])
                        ->orderBy('c.name', 'ASC');
                },
            ])
            ->add('brands', EntityType::class, [
                'label' => 'Marcas',
                'class' => Brand::class,
                'choice_label' => 'name',
                'multiple' => true,
                'required' => false,
                'query_builder' => function (EntityRepository $er) use ($options) {
                    return $er->createQueryBuilder('b')
                        ->where('b.business = :business')
                        ->setParameter('business', $options['current_business'])
                        ->orderBy('b.name', 'ASC');
                },
            ])
            ->add('updatedSince', DateType::class, [
                'label' => 'Productos modificados desde',
                'required' => false,
                'widget' => 'single_text',
            ])
            ->add('includeBarcode', CheckboxType::class, [
                'label' => 'Incluir código de barras',
                'required' => false,
            ])
            ->add('barcodeSource', ChoiceType::class, [
                'label' => 'Origen del código',
                'choices' => [
                    'EAN del producto (si existe)' => 'ean',
                    'SKU como código de barras' => 'sku',
                ],
                'required' => false,
            ])
            ->add('showPrice', CheckboxType::class, [
                'label' => 'Mostrar precio al público',
                'required' => false,
            ])
            ->add('showOnlyName', CheckboxType::class, [
                'label' => 'Solo imprimir nombre',
                'required' => false,
            ])
            ->add('includeLabelImage', CheckboxType::class, [
                'label' => 'Incluir imagen en la etiqueta',
                'required' => false,
            ])
            ->add('labelsPerProduct', IntegerType::class, [
                'label' => 'Cantidad de etiquetas por producto',
                'required' => false,
                'attr' => [
                    'min' => 1,
                ],
            ])
            ->add('labelImagePath', HiddenType::class, [
                'required' => false,
                'attr' => [
                    'data-label-image-upload-target' => 'path',
                ],
            ])
            ->add('labelImageFile', FileType::class, [
                'label' => 'Imagen para etiquetas',
                'mapped' => false,
                'required' => false,
                'help' => 'Recomendado: 350x600 px (3,5 x 6 cm). La imagen se recorta automáticamente.',
                'attr' => [
                    'accept' => 'image/*',
                    'data-label-image-upload-target' => 'file',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'current_business' => null,
        ]);
    }
}
