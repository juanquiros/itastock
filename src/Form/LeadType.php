<?php

namespace App\Form;

use App\Entity\Lead;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;

class LeadType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nombre',
                'constraints' => [
                    new NotBlank(message: 'El nombre es requerido.'),
                ],
                'attr' => [
                    'placeholder' => 'Tu nombre completo',
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
            ->add('message', TextareaType::class, [
                'label' => 'Mensaje',
                'constraints' => [
                    new NotBlank(message: 'Cuéntanos en qué podemos ayudarte.'),
                ],
                'attr' => [
                    'rows' => 5,
                    'placeholder' => 'Cuéntanos sobre tu negocio y necesidades',
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
