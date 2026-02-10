<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class UserType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $currentRole = $options['current_role'] ?? 'SELLER';
        $membership = $options['membership'] ?? null;

        $builder
            ->add('fullName', TextType::class, [
                'label' => 'Nombre completo',
            ])
            ->add('email', EmailType::class, [
                'label' => 'Correo electrónico',
            ])
            ->add('role', ChoiceType::class, [
                'label' => 'Rol',
                'expanded' => false,
                'multiple' => false,
                'choices' => [
                    'Administrador' => 'ADMIN',
                    'Vendedor' => 'SELLER',
                ],
                'mapped' => false,
                'data' => $currentRole,
            ])
            ->add('posNumber', IntegerType::class, [
                'label' => 'Puesto de venta',
                'required' => false,
                'attr' => [
                    'min' => 1,
                ],
                'help' => 'El administrador del comercio usa el puesto 1. Los demás usuarios deben tener un número propio.',
            ])
            ->add('ticketPaperSize', ChoiceType::class, [
                'label' => 'Tamaño de hoja para impresión de ticket',
                'choices' => [
                    'A4' => User::PRINT_PAPER_A4,
                    'Térmica 80 mm' => User::PRINT_PAPER_80MM,
                    'Térmica 58 mm' => User::PRINT_PAPER_58MM,
                ],
                'help' => 'Se aplica al botón Imprimir en el ticket de venta.',
            ])
            ->add('arcaEnabledForThisCashier', CheckboxType::class, [
                'label' => 'Habilitar facturación ARCA para esta caja',
                'required' => false,
                'mapped' => false,
                'data' => $membership?->isArcaEnabledForThisCashier() ?? false,
            ])
            ->add('arcaMode', ChoiceType::class, [
                'label' => 'Modo ARCA',
                'required' => false,
                'mapped' => false,
                'choices' => [
                    'Solo remito' => 'REMITO_ONLY',
                    'Factura' => 'INVOICE',
                ],
                'data' => $membership?->getArcaMode() ?? 'REMITO_ONLY',
            ])
            ->add('arcaPosNumber', IntegerType::class, [
                'label' => 'Punto de venta ARCA',
                'required' => false,
                'mapped' => false,
                'attr' => [
                    'min' => 1,
                ],
                'data' => $membership?->getArcaPosNumber(),
            ])
            ->add('arcaAutoIssueInvoice', CheckboxType::class, [
                'label' => 'Emitir factura automáticamente en esta caja',
                'required' => false,
                'mapped' => false,
                'data' => $membership?->isArcaAutoIssueInvoice() ?? false,
                'help' => 'Solo aplica si el modo ARCA está configurado en Factura.',
            ])
            ->add('plainPassword', PasswordType::class, [
                'label' => $options['require_password'] ? 'Contraseña' : 'Nueva contraseña',
                'mapped' => false,
                'required' => $options['require_password'],
                'attr' => [
                    'autocomplete' => 'new-password',
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'require_password' => true,
            'current_role' => 'SELLER',
            'membership' => null,
        ]);
    }
}
