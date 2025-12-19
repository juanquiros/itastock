<?php

namespace App\Form;

use App\Entity\PublicPage;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class PublicPageType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('slug', TextType::class, [
                'label' => 'Slug',
                'help' => 'Identificador único (ej: home, features, pricing)',
            ])
            ->add('title', TextType::class, [
                'label' => 'Título',
            ])
            ->add('metaDescription', TextType::class, [
                'label' => 'Meta descripción',
                'required' => false,
            ])
            ->add('bodyHtml', TextareaType::class, [
                'label' => 'Contenido (HTML seguro)',
                'attr' => ['rows' => 12],
            ])
            ->add('isPublished', CheckboxType::class, [
                'label' => 'Publicada',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PublicPage::class,
        ]);
    }
}
