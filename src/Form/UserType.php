<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
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
        ]);
    }
}
