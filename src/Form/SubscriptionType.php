<?php

namespace App\Form;

use App\Entity\Plan;
use App\Entity\Subscription;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\GreaterThanOrEqual;

class SubscriptionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('plan', EntityType::class, [
                'class' => Plan::class,
                'choice_label' => 'name',
                'label' => 'Plan',
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'Estado',
                'choices' => [
                    'Trial' => Subscription::STATUS_TRIAL,
                    'Activo' => Subscription::STATUS_ACTIVE,
                    'Vencido' => Subscription::STATUS_PAST_DUE,
                    'Cancelado' => Subscription::STATUS_CANCELED,
                    'Suspendido' => Subscription::STATUS_SUSPENDED,
                ],
            ])
            ->add('startAt', DateTimeType::class, [
                'label' => 'Inicio',
                'widget' => 'single_text',
            ])
            ->add('trialEndsAt', DateTimeType::class, [
                'label' => 'Fin de trial',
                'widget' => 'single_text',
                'required' => false,
                'constraints' => [
                    new GreaterThanOrEqual('today'),
                ],
            ])
            ->add('endAt', DateTimeType::class, [
                'label' => 'Fin de suscripción',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'Notas',
                'required' => false,
                'attr' => ['rows' => 4],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Subscription::class,
        ]);
    }
}
