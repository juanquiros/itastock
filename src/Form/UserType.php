<?php

namespace App\Form;

use App\Entity\Business;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class UserType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $currentRole = 'ROLE_SELLER';
        if ($builder->getData() instanceof User) {
            foreach ($builder->getData()->getRoles() as $role) {
                if ('ROLE_USER' !== $role) {
                    $currentRole = $role;
                    break;
                }
            }
        }

        $builder
            ->add('fullName', TextType::class, [
                'label' => 'Nombre completo',
            ])
            ->add('email', EmailType::class, [
                'label' => 'Correo electrónico',
            ])
            ->add('business', EntityType::class, [
                'class' => Business::class,
                'choice_label' => 'name',
                'label' => 'Comercio',
                'placeholder' => 'Seleccionar comercio',
            ])
            ->add('role', ChoiceType::class, [
                'label' => 'Rol',
                'expanded' => false,
                'multiple' => false,
                'choices' => [
                    'Administrador' => 'ROLE_ADMIN',
                    'Vendedor' => 'ROLE_SELLER',
                ],
                'mapped' => false,
                'data' => $currentRole,
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
        ]);
    }
}
