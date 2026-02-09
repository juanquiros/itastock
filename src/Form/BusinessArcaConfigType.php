<?php

namespace App\Form;

use App\Entity\BusinessArcaConfig;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class BusinessArcaConfigType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('arcaEnabled', CheckboxType::class, [
                'label' => 'Habilitar ARCA',
                'required' => false,
            ])
            ->add('arcaEnvironment', ChoiceType::class, [
                'label' => 'Ambiente',
                'choices' => [
                    'Homologación' => BusinessArcaConfig::ENV_HOMO,
                    'Producción' => BusinessArcaConfig::ENV_PROD,
                ],
            ])
            ->add('taxPayerType', ChoiceType::class, [
                'label' => 'Tipo de contribuyente',
                'choices' => [
                    'Monotributo' => BusinessArcaConfig::TAX_PAYER_MONOTRIBUTO,
                    'Responsable inscripto' => BusinessArcaConfig::TAX_PAYER_RESPONSABLE_INSCRIPTO,
                ],
            ])
            ->add('cuitEmisor', TextType::class, [
                'label' => 'CUIT emisor',
                'required' => false,
            ])
            ->add('certPem', TextareaType::class, [
                'label' => 'Certificado (PEM)',
                'required' => false,
                'attr' => [
                    'rows' => 6,
                    'placeholder' => '-----BEGIN CERTIFICATE-----',
                ],
            ])
            ->add('privateKeyPem', TextareaType::class, [
                'label' => 'Key privada (PEM)',
                'required' => false,
                'attr' => [
                    'rows' => 6,
                    'placeholder' => '-----BEGIN PRIVATE KEY-----',
                ],
            ])
            ->add('passphrase', TextType::class, [
                'label' => 'Passphrase (opcional)',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => BusinessArcaConfig::class,
        ]);
    }
}
