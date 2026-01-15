<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class BusinessLabelSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
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
            'data_class' => null,
        ]);
    }
}
