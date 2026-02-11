<?php

namespace App\Form;

use App\Entity\Customer;
use App\Entity\PriceList;
use App\Repository\BusinessArcaConfigRepository;
use App\Security\BusinessContext;
use App\Service\ArcaWsfeService;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Validator\Constraints\Regex;

class CustomerType extends AbstractType
{
    public function __construct(
        private readonly BusinessContext $businessContext,
        private readonly BusinessArcaConfigRepository $configRepository,
        private readonly ArcaWsfeService $wsfeService,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nombre',
                'attr' => ['placeholder' => 'Nombre o razón social'],
            ])
            ->add('documentType', ChoiceType::class, [
                'label' => 'Tipo de documento',
                'required' => false,
                'placeholder' => 'Seleccionar',
                'choices' => [
                    'DNI' => Customer::TYPE_DNI,
                    'CUIT' => Customer::TYPE_CUIT,
                    'Otro' => Customer::TYPE_OTHER,
                ],
            ])
            ->add('documentNumber', TextType::class, [
                'label' => 'Número de documento',
                'required' => false,
                'constraints' => [
                    new Regex(pattern: '/^\d+$/', message: 'Solo números.'),
                ],
            ])
            ->add('phone', TextType::class, [
                'label' => 'Teléfono',
                'required' => false,
            ])
            ->add('email', EmailType::class, [
                'label' => 'Email',
                'required' => false,
            ])
            ->add('address', TextType::class, [
                'label' => 'Dirección',
                'required' => false,
            ])
            ->add('customerType', ChoiceType::class, [
                'label' => 'Tipo de cliente',
                'choices' => [
                    'Consumidor final' => Customer::CUSTOMER_CONSUMIDOR_FINAL,
                    'Minorista' => Customer::CUSTOMER_MINORISTA,
                    'Mayorista' => Customer::CUSTOMER_MAYORISTA,
                    'Revendedor' => Customer::CUSTOMER_REVENDEDOR,
                ],
            ])
            ->add('priceList', ChoiceType::class, [
                'label' => 'Lista de precios',
                'required' => false,
                'placeholder' => 'Lista default',
                'choices' => $options['price_lists'],
                'choice_label' => static function (PriceList $list) {
                    return $list->getName().($list->isDefault() ? ' (Default)' : '');
                },
            ])
            ->add('isActive', CheckboxType::class, [
                'label' => 'Cliente activo',
                'required' => false,
            ])
        ;

        if ($this->authorizationChecker->isGranted('BUSINESS_ADMIN')) {
            $business = $this->businessContext->requireCurrentBusiness();
            $config = $this->configRepository->findOneBy(['business' => $business]);
            $choices = [];
            $help = 'Opcional. Usalo para facturación ARCA.';

            if ($config) {
                $choices = array_flip($this->wsfeService->getCondicionIvaReceptorOptions($config));
                if ($this->wsfeService->getCondicionIvaReceptorError($config)) {
                    $help = 'No se pudieron cargar opciones desde ARCA. Podés guardar igual y reintentar luego.';
                }
            } else {
                $help = 'Configurá ARCA para poder cargar las opciones.';
            }

            $builder->add('ivaConditionId', ChoiceType::class, [
                'label' => 'Condición frente al IVA',
                'required' => false,
                'placeholder' => 'Seleccionar',
                'choices' => $choices,
                'help' => $help,
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Customer::class,
            'price_lists' => [],
        ]);
    }
}
