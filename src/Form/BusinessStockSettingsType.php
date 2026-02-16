<?php

namespace App\Form;

use App\Entity\Business;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class BusinessStockSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('allowNegativeStock', CheckboxType::class, [
                'label' => 'Permitir vender con stock negativo',
                'required' => false,
                'help' => 'Si está activo, el sistema permitirá vender aunque no haya existencia cargada. Recomendado solo si tu operación requiere no frenar la caja. Controlar luego con reposición/ajustes.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Business::class,
        ]);
    }
}
