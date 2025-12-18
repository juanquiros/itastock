<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class ResetPasswordType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('plainPassword', RepeatedType::class, [
            'type' => PasswordType::class,
            'invalid_message' => 'Las contraseñas no coinciden.',
            'first_options' => [
                'label' => 'Nueva contraseña',
                'attr' => ['autocomplete' => 'new-password'],
            ],
            'second_options' => [
                'label' => 'Repetir contraseña',
                'attr' => ['autocomplete' => 'new-password'],
            ],
            'constraints' => [
                new Assert\NotBlank(),
                new Assert\Length(min: 6, max: 4096),
            ],
            'mapped' => false,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([]);
    }
}
