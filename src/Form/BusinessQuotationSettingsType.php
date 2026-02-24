<?php

namespace App\Form;

use App\Entity\Business;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class BusinessQuotationSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('quotationHeaderLines', TextareaType::class, [
                'label' => 'Líneas del encabezado',
                'required' => false,
                'attr' => ['rows' => 4],
            ])
            ->add('quotationFooterLines', TextareaType::class, [
                'label' => 'Líneas del pie',
                'required' => false,
                'attr' => ['rows' => 4],
            ])
            ->add('quotationHeaderImagePath', HiddenType::class, [
                'required' => false,
                'attr' => ['data-ticket-image-upload-target' => 'path'],
            ])
            ->add('quotationFooterImagePath', HiddenType::class, [
                'required' => false,
                'attr' => ['data-ticket-image-upload-target' => 'path'],
            ])
            ->add('quotationHeaderImageFile', FileType::class, [
                'label' => 'Icono del presupuesto',
                'mapped' => false,
                'required' => false,
                'attr' => [
                    'accept' => 'image/*',
                    'data-ticket-image-upload-target' => 'file',
                ],
                'help' => 'Se optimiza en el navegador y se guarda como data URL.',
            ])
            ->add('quotationFooterImageFile', FileType::class, [
                'label' => 'Imagen final del presupuesto',
                'mapped' => false,
                'required' => false,
                'attr' => [
                    'accept' => 'image/*',
                    'data-ticket-image-upload-target' => 'file',
                ],
                'help' => 'Se optimiza en el navegador y se guarda como data URL.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Business::class,
        ]);
    }
}
