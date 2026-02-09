<?php

namespace App\Form;

use App\Entity\BusinessArcaConfig;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\When;
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
            ->add('certFile', FileType::class, [
                'label' => 'Certificado (.crt/.pem)',
                'required' => false,
                'mapped' => false,
                'attr' => [
                    'accept' => '.crt,.pem',
                ],
                'constraints' => [
                    new File([
                        'maxSize' => '200k',
                        'mimeTypes' => [
                            'application/x-x509-ca-cert',
                            'application/pem-certificate-chain',
                            'text/plain',
                        ],
                        'mimeTypesMessage' => 'Subí un certificado PEM válido.',
                    ]),
                    new When(
                        expression: 'this.getParent().get("arcaEnabled").getData() == true && (this.getParent().get("certPem").getData() == null || this.getParent().get("certPem").getData() == "")',
                        constraints: [new NotBlank(message: 'Subí un certificado o pegá el PEM.')],
                    ),
                ],
            ])
            ->add('certPem', TextareaType::class, [
                'label' => 'Certificado (PEM)',
                'required' => false,
                'attr' => [
                    'rows' => 6,
                    'placeholder' => '-----BEGIN CERTIFICATE-----',
                ],
            ])
            ->add('keyFile', FileType::class, [
                'label' => 'Clave privada (.key/.pem)',
                'required' => false,
                'mapped' => false,
                'attr' => [
                    'accept' => '.key,.pem',
                ],
                'constraints' => [
                    new File([
                        'maxSize' => '200k',
                        'mimeTypes' => [
                            'application/octet-stream',
                            'text/plain',
                        ],
                        'mimeTypesMessage' => 'Subí una clave PEM válida.',
                    ]),
                    new When(
                        expression: 'this.getParent().get("arcaEnabled").getData() == true && (this.getParent().get("privateKeyPem").getData() == null || this.getParent().get("privateKeyPem").getData() == "")',
                        constraints: [new NotBlank(message: 'Subí una clave privada o pegá el PEM.')],
                    ),
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
