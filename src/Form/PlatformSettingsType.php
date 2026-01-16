<?php

namespace App\Form;

use App\Entity\PlatformSettings;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class PlatformSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('barcodeScanSoundPath', HiddenType::class, [
                'required' => false,
            ])
            ->add('whatsappLink', TextType::class, [
                'label' => 'Link o número de WhatsApp',
                'required' => false,
                'help' => 'Podés ingresar un link completo (https://wa.me/...) o solo el número.',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PlatformSettings::class,
        ]);
    }
}
