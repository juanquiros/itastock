<?php

namespace App\Form;

use App\Entity\Lead;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;

class LeadDemoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nombre de contacto',
                'required' => true,
                'attr' => [
                    'placeholder' => 'Tu nombre',
                ],
            ])
            ->add('businessName', TextType::class, [
                'label' => 'Nombre del comercio',
                'constraints' => [
                    new NotBlank(message: 'El nombre del comercio es requerido.'),
                ],
                'attr' => [
                    'placeholder' => 'Nombre del comercio',
                ],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Email',
                'constraints' => [
                    new NotBlank(message: 'El email es requerido.'),
                    new Email(message: 'Ingresa un email válido.'),
                ],
                'attr' => [
                    'placeholder' => 'tu@email.com',
                ],
            ])
            ->add('phone', TelType::class, [
                'label' => 'Teléfono',
                'required' => false,
                'attr' => [
                    'placeholder' => 'Opcional',
                ],
            ])
            ->add('captchaAnswer', IntegerType::class, [
                'label' => 'Validación',
                'mapped' => false,
                'required' => true,
                'attr' => [
                    'placeholder' => 'Ingresá el resultado',
                ],
                'constraints' => [
                    new NotBlank(message: 'Respondé la validación.'),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Lead::class,
        ]);
    }
}
