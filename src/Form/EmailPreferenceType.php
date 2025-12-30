<?php

namespace App\Form;

use App\Entity\EmailPreference;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class EmailPreferenceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $hours = range(0, 23);
        $minutes = range(0, 59);

        $builder
            ->add('enabled', CheckboxType::class, [
                'label' => 'Habilitar emails',
                'required' => false,
            ])
            ->add('subscriptionAlertsEnabled', CheckboxType::class, [
                'label' => 'Alertas de suscripción',
                'required' => false,
            ])
            ->add('reportDailyEnabled', CheckboxType::class, [
                'label' => 'Reporte diario',
                'required' => false,
            ])
            ->add('reportWeeklyEnabled', CheckboxType::class, [
                'label' => 'Reporte semanal',
                'required' => false,
            ])
            ->add('reportMonthlyEnabled', CheckboxType::class, [
                'label' => 'Reporte mensual',
                'required' => false,
            ])
            ->add('reportAnnualEnabled', CheckboxType::class, [
                'label' => 'Reporte anual',
                'required' => false,
            ])
            ->add('deliveryHour', ChoiceType::class, [
                'label' => 'Hora de envío',
                'choices' => array_combine($hours, $hours),
            ])
            ->add('deliveryMinute', ChoiceType::class, [
                'label' => 'Minuto',
                'choices' => array_combine($minutes, $minutes),
            ])
            ->add('timezone', TextType::class, [
                'label' => 'Zona horaria',
                'mapped' => false,
                'data' => $options['timezone'],
                'disabled' => true,
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EmailPreference::class,
            'timezone' => 'America/Argentina/Buenos_Aires',
        ]);
    }
}
