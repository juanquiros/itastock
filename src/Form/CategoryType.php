<?php

namespace App\Form;

use App\Entity\Category;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CategoryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nombre',
            ])
            ->add('ivaRate', TextType::class, [
                'label' => 'IVA (%)',
                'required' => false,
                'help' => 'Ej: 21, 10.5, 0',
            ]);

        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event): void {
            $data = $event->getData();
            if (!is_array($data) || !array_key_exists('ivaRate', $data)) {
                return;
            }

            $normalized = $this->normalizeRate($data['ivaRate']);
            if ($normalized === null && $data['ivaRate'] !== null && $data['ivaRate'] !== '') {
                $event->getForm()->get('ivaRate')->addError(new FormError('Ingresá un IVA válido (solo números y coma/punto).'));
            }

            $data['ivaRate'] = $normalized;
            $event->setData($data);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Category::class,
        ]);
    }

    private function normalizeRate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $raw = str_replace(',', '.', trim((string) $value));
        if ($raw === '' || !is_numeric($raw)) {
            return null;
        }

        if ((float) $raw < 0) {
            return null;
        }

        return bcadd($raw, '0', 2);
    }
}
