<?php

namespace App\Form;

use App\Entity\CatalogBrand;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\String\Slugger\SluggerInterface;

class CatalogBrandType extends AbstractType
{
    public function __construct(private readonly SluggerInterface $slugger)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nombre',
            ])
            ->add('logoPath', HiddenType::class, [
                'required' => false,
                'attr' => [
                    'data-brand-logo-upload-target' => 'logoPath',
                ],
            ])
            ->add('logoFile', FileType::class, [
                'label' => 'Logo (archivo)',
                'mapped' => false,
                'required' => false,
                'attr' => [
                    'accept' => 'image/*',
                    'data-brand-logo-upload-target' => 'logoFile',
                ],
                'help' => 'Se optimiza en el navegador y se guarda como data URL.',
            ])
            ->add('isActive', CheckboxType::class, [
                'label' => 'Activo',
                'required' => false,
            ]);

        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event): void {
            $data = $event->getData();
            if (!is_array($data)) {
                return;
            }

            if (!empty($data['name']) && empty($data['slug'])) {
                $data['slug'] = strtolower($this->slugger->slug((string) $data['name'])->toString());
            }

            $event->setData($data);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CatalogBrand::class,
        ]);
    }
}
