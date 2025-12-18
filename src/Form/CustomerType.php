<?php

namespace App\Form;

use App\Entity\Customer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Regex;

class CustomerType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nombre',
                'attr' => ['placeholder' => 'Nombre o razón social'],
            ])
            ->add('documentType', ChoiceType::class, [
                'label' => 'Tipo de documento',
                'required' => false,
                'placeholder' => 'Seleccionar',
                'choices' => [
                    'DNI' => Customer::TYPE_DNI,
                    'CUIT' => Customer::TYPE_CUIT,
                    'Otro' => Customer::TYPE_OTHER,
                ],
            ])
            ->add('documentNumber', TextType::class, [
                'label' => 'Número de documento',
                'required' => false,
                'constraints' => [
                    new Regex(pattern: '/^\d+$/', message: 'Solo números.'),
                ],
            ])
            ->add('phone', TextType::class, [
                'label' => 'Teléfono',
                'required' => false,
            ])
            ->add('address', TextType::class, [
                'label' => 'Dirección',
                'required' => false,
            ])
            ->add('customerType', ChoiceType::class, [
                'label' => 'Tipo de cliente',
                'choices' => [
                    'Consumidor final' => Customer::CUSTOMER_CONSUMIDOR_FINAL,
                    'Minorista' => Customer::CUSTOMER_MINORISTA,
                    'Mayorista' => Customer::CUSTOMER_MAYORISTA,
                    'Revendedor' => Customer::CUSTOMER_REVENDEDOR,
                ],
            ])
            ->add('isActive', CheckboxType::class, [
                'label' => 'Cliente activo',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Customer::class,
        ]);
    }
}
