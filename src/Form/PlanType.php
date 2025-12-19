<?php

namespace App\Form;

use App\Entity\Plan;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

class PlanType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', TextType::class, [
                'label' => 'Código',
                'help' => 'Identificador único del plan (ej: basic, pro)',
            ])
            ->add('name', TextType::class, [
                'label' => 'Nombre',
            ])
            ->add('priceMonthly', NumberType::class, [
                'label' => 'Precio mensual',
                'scale' => 2,
            ])
            ->add('currency', TextType::class, [
                'label' => 'Moneda',
                'empty_data' => 'ARS',
            ])
            ->add('featuresJson', TextareaType::class, [
                'label' => 'Características (JSON array de strings)',
                'required' => false,
                'attr' => ['rows' => 6, 'placeholder' => "[\"Punto de venta\", \"Reportes\"]"],
            ])
            ->add('isActive', CheckboxType::class, [
                'label' => 'Activo',
                'required' => false,
            ])
            ->add('isFeatured', CheckboxType::class, [
                'label' => 'Destacado',
                'required' => false,
            ])
            ->add('sortOrder', IntegerType::class, [
                'label' => 'Orden',
                'empty_data' => '0',
            ])
        ;

        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event) {
            /** @var Plan $plan */
            $plan = $event->getData();
            $form = $event->getForm();
            $features = $plan->getFeaturesJson();

            if ($features === null || $features === '') {
                return;
            }

            $decoded = json_decode($features, true);
            if (!is_array($decoded)) {
                $form->get('featuresJson')->addError(new FormError('El formato debe ser un arreglo JSON de textos.'));

                return;
            }

            foreach ($decoded as $item) {
                if (!is_string($item)) {
                    $form->get('featuresJson')->addError(new FormError('Cada característica debe ser texto.'));
                    break;
                }
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Plan::class,
        ]);
    }
}
