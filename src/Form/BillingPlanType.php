<?php

namespace App\Form;

use App\Entity\BillingPlan;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class BillingPlanType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nombre',
            ])
            ->add('price', NumberType::class, [
                'label' => 'Precio',
                'scale' => 2,
            ])
            ->add('currency', TextType::class, [
                'label' => 'Moneda',
                'empty_data' => 'ARS',
            ])
            ->add('frequency', IntegerType::class, [
                'label' => 'Frecuencia',
                'empty_data' => '1',
            ])
            ->add('frequencyType', TextType::class, [
                'label' => 'Tipo de frecuencia',
                'help' => 'Ejemplo: months, days',
            ])
            ->add('isActive', CheckboxType::class, [
                'label' => 'Activo',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => BillingPlan::class,
        ]);
    }
}
