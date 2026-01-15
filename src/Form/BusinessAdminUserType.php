<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class BusinessAdminUserType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $requirePassword = (bool) $options['require_password'];

        $builder
            ->add('fullName', TextType::class, [
                'label' => 'Nombre completo',
                'constraints' => [new NotBlank()],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Email',
                'constraints' => [
                    new NotBlank(),
                    new Email(),
                ],
            ])
            ->add('plainPassword', PasswordType::class, [
                'label' => 'Contraseña temporal',
                'mapped' => false,
                'required' => $requirePassword,
                'constraints' => $requirePassword
                    ? [
                        new NotBlank(),
                        new Length(min: 8),
                    ]
                    : [
                        new Length(min: 8),
                    ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'require_password' => true,
        ]);
    }
}
