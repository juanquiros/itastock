<?php

namespace App\Form;

use App\Entity\Business;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class BusinessTicketSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('ticketHeaderLines', TextareaType::class, [
                'label' => 'Líneas del encabezado',
                'required' => false,
                'help' => 'Una línea por renglón. Ejemplo: "CUIT: 2037220408".',
                'attr' => [
                    'rows' => 4,
                ],
            ])
            ->add('ticketFooterLines', TextareaType::class, [
                'label' => 'Líneas del pie',
                'required' => false,
                'help' => 'Una línea por renglón. Ejemplo: "Gracias por su compra".',
                'attr' => [
                    'rows' => 4,
                ],
            ])
            ->add('ticketHeaderImagePath', HiddenType::class, [
                'required' => false,
                'attr' => [
                    'data-ticket-image-upload-target' => 'path',
                ],
            ])
            ->add('ticketFooterImagePath', HiddenType::class, [
                'required' => false,
                'attr' => [
                    'data-ticket-image-upload-target' => 'path',
                ],
            ])
            ->add('ticketHeaderImageFile', FileType::class, [
                'label' => 'Icono del ticket',
                'mapped' => false,
                'required' => false,
                'attr' => [
                    'accept' => 'image/*',
                    'data-ticket-image-upload-target' => 'file',
                ],
                'help' => 'Se optimiza en el navegador y se guarda como data URL.',
            ])
            ->add('ticketFooterImageFile', FileType::class, [
                'label' => 'Imagen final del ticket',
                'mapped' => false,
                'required' => false,
                'attr' => [
                    'accept' => 'image/*',
                    'data-ticket-image-upload-target' => 'file',
                ],
                'help' => 'Se optimiza en el navegador y se guarda como data URL.',
            ])
            ->add('ticketPrinterName', TextType::class, [
                'label' => 'Impresora preferida',
                'required' => false,
                'help' => 'Nombre de impresora configurada en el sistema operativo.',
            ])
            ->add('ticketPrinterSettings', TextareaType::class, [
                'label' => 'Parámetros de impresora',
                'required' => false,
                'help' => 'Opcional. Configuración adicional para futuras integraciones de impresión directa.',
                'attr' => [
                    'rows' => 3,
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Business::class,
        ]);
    }
}
