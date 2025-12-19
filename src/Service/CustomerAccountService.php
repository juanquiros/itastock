<?php

namespace App\Service;

use App\Entity\Business;
use App\Entity\Customer;
use App\Entity\CustomerAccountMovement;
use App\Entity\Sale;
use App\Entity\User;
use App\Repository\CustomerAccountMovementRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class CustomerAccountService
{
    public function __construct(
        private readonly CustomerAccountMovementRepository $movementRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function getBalance(Customer $customer): string
    {
        return $this->movementRepository->getBalance($customer);
    }

    /**
     * @return CustomerAccountMovement[]
     */
    public function getMovements(Customer $customer, ?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null, ?string $type = null): array
    {
        return $this->movementRepository->findForCustomer($customer, $from, $to, $type);
    }

    public function addDebitForSale(Sale $sale): void
    {
        $customer = $sale->getCustomer();
        if (!$customer instanceof Customer) {
            throw new AccessDeniedException('No se puede generar deuda sin cliente.');
        }

        $movement = new CustomerAccountMovement();
        $movement->setBusiness($sale->getBusiness());
        $movement->setCustomer($customer);
        $movement->setType(CustomerAccountMovement::TYPE_DEBIT);
        $movement->setAmount($sale->getTotal());
        $movement->setReferenceType(CustomerAccountMovement::REFERENCE_SALE);
        $movement->setReferenceId($sale->getId());
        $movement->setCreatedBy($sale->getCreatedBy());
        $movement->setNote('Venta en cuenta');

        $this->entityManager->persist($movement);
    }

    public function addCreditPayment(Customer $customer, string $amount, string $method, ?string $note, User $actor): CustomerAccountMovement
    {
        $value = (float) $amount;
        if ($value <= 0) {
            throw new AccessDeniedException('El importe debe ser mayor a cero.');
        }

        if (!$customer->isActive()) {
            throw new AccessDeniedException('No podés registrar pagos para un cliente inactivo.');
        }

        $movement = new CustomerAccountMovement();
        $movement->setBusiness($customer->getBusiness());
        $movement->setCustomer($customer);
        $movement->setType(CustomerAccountMovement::TYPE_CREDIT);
        $movement->setAmount(number_format($value, 2, '.', ''));
        $movement->setReferenceType(CustomerAccountMovement::REFERENCE_PAYMENT);
        $movement->setNote(trim(sprintf('Pago %s%s', $method, $note ? ' · '.$note : '')));
        $movement->setCreatedBy($actor);

        $this->entityManager->persist($movement);

        return $movement;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findDebtors(Business $business, float $minBalance): array
    {
        return $this->movementRepository->findDebtors($business, $minBalance);
    }
}
