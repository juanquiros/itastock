<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\NotBlank;

class StockImportType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('file', FileType::class, [
                'label' => 'Archivo CSV',
                'mapped' => false,
                'constraints' => [
                    new NotBlank(message: 'Subí un archivo CSV'),
                    new File(mimeTypes: ['text/plain', 'text/csv', 'text/comma-separated-values', 'application/vnd.ms-excel']),
                ],
            ])
            ->add('dryRun', CheckboxType::class, [
                'required' => false,
                'mapped' => false,
                'label' => 'Dry run (solo validar, sin aplicar cambios)',
            ]);
    }
}
