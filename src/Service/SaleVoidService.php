<?php

namespace App\Service;

use App\Entity\Payment;
use App\Entity\Sale;
use App\Entity\SaleItem;
use App\Entity\StockMovement;
use App\Entity\User;
use App\Repository\CashSessionRepository;
use Doctrine\ORM\EntityManagerInterface;

class SaleVoidService
{
    private const TIMEZONE = 'America/Argentina/Buenos_Aires';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CashSessionRepository $cashSessionRepository,
        private readonly CustomerAccountService $customerAccountService,
    ) {
    }

    public function voidSale(Sale $sale, User $user, string $reason): void
    {
        $this->assertCanVoid($sale);

        $reason = trim($reason);
        if ($reason === '') {
            throw new \DomainException('Ingresá un motivo para anular la venta.');
        }

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $sale->setStatus(Sale::STATUS_VOIDED);
            $sale->setVoidedAt(new \DateTimeImmutable('now', $this->getTimezone()));
            $sale->setVoidedBy($user);
            $sale->setVoidReason($reason);

            $this->revertStock($sale, $user);
            $this->revertPayments($sale);
            $this->revertCustomerAccount($sale, $user, $reason);

            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();

            throw $exception;
        }
    }

    private function assertCanVoid(Sale $sale): void
    {
        if ($sale->getStatus() !== Sale::STATUS_CONFIRMED) {
            throw new \DomainException('La venta ya fue anulada.');
        }

        $createdAt = $sale->getCreatedAt();
        if (!$createdAt instanceof \DateTimeImmutable) {
            throw new \DomainException('No se puede anular una venta sin fecha.');
        }

        $tz = $this->getTimezone();
        $today = (new \DateTimeImmutable('now', $tz))->format('Y-m-d');
        $saleDate = $createdAt->setTimezone($tz)->format('Y-m-d');

        if ($saleDate !== $today) {
            throw new \DomainException('La venta no puede anularse fuera del día.');
        }

        $business = $sale->getBusiness();
        $seller = $sale->getCreatedBy();
        $openSession = $seller !== null
            ? $this->cashSessionRepository->findOpenForUser($business, $seller)
            : $this->cashSessionRepository->findOpenForBusiness($business);

        if ($openSession === null) {
            throw new \DomainException('La venta no puede anularse con la caja cerrada.');
        }
    }

    private function revertStock(Sale $sale, User $actor): void
    {
        foreach ($sale->getItems() as $item) {
            if (!$item instanceof SaleItem) {
                continue;
            }

            $product = $item->getProduct();
            if ($product === null) {
                continue;
            }

            $qty = bcadd($item->getQty(), '0', 3);
            $product->adjustStock($qty);

            $movement = new StockMovement();
            $movement->setProduct($product);
            $movement->setType(StockMovement::TYPE_SALE_VOID);
            $movement->setQty($qty);
            $movement->setReference(sprintf('Anulación venta #%d', $sale->getId()));
            $movement->setCreatedBy($actor);

            $this->entityManager->persist($movement);
        }
    }

    private function revertPayments(Sale $sale): void
    {
        $originalPayments = $sale->getPayments()->toArray();

        foreach ($originalPayments as $payment) {
            if (!$payment instanceof Payment) {
                continue;
            }

            $reverse = new Payment();
            $reverse->setSale($sale);
            $reverse->setAmount(bcsub('0', $payment->getAmount(), 2));
            $reverse->setMethod($payment->getMethod());
            $reverse->setReferenceType(Payment::REFERENCE_SALE_VOID);
            $reverse->setReferenceId($sale->getId());

            $sale->addPayment($reverse);
            $this->entityManager->persist($reverse);
        }
    }

    private function revertCustomerAccount(Sale $sale, User $actor, string $reason): void
    {
        $customer = $sale->getCustomer();
        if ($customer === null || !$this->hasAccountPayment($sale)) {
            return;
        }

        $this->customerAccountService->addCreditForSaleVoid($sale, $actor, $reason);
    }

    private function hasAccountPayment(Sale $sale): bool
    {
        foreach ($sale->getPayments() as $payment) {
            if ($payment instanceof Payment && $payment->getMethod() === 'ACCOUNT') {
                return true;
            }
        }

        return false;
    }

    private function getTimezone(): \DateTimeZone
    {
        return new \DateTimeZone(self::TIMEZONE);
    }
}
