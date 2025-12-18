<?php

namespace App\Repository;

use App\Entity\Business;
use App\Entity\Customer;
use App\Entity\CustomerAccountMovement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CustomerAccountMovement>
 */
class CustomerAccountMovementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CustomerAccountMovement::class);
    }

    public function getBalance(Customer $customer): string
    {
        $qb = $this->createQueryBuilder('m')
            ->select("COALESCE(SUM(CASE WHEN m.type = :debit THEN m.amount ELSE -m.amount END), 0) AS balance")
            ->andWhere('m.customer = :customer')
            ->setParameter('customer', $customer)
            ->setParameter('debit', CustomerAccountMovement::TYPE_DEBIT);

        $result = $qb->getQuery()->getSingleScalarResult();

        return number_format((float) $result, 2, '.', '');
    }

    /**
     * @return CustomerAccountMovement[]
     */
    public function findForCustomer(Customer $customer, ?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null, ?string $type = null): array
    {
        $qb = $this->createQueryBuilder('m')
            ->andWhere('m.customer = :customer')
            ->setParameter('customer', $customer)
            ->orderBy('m.createdAt', 'DESC');

        if ($from !== null) {
            $qb->andWhere('m.createdAt >= :from')->setParameter('from', $from);
        }

        if ($to !== null) {
            $qb->andWhere('m.createdAt <= :to')->setParameter('to', $to);
        }

        if ($type !== null && in_array($type, [CustomerAccountMovement::TYPE_DEBIT, CustomerAccountMovement::TYPE_CREDIT], true)) {
            $qb->andWhere('m.type = :type')->setParameter('type', $type);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findDebtors(Business $business, float $minBalance): array
    {
        $balanceExpression = 'COALESCE(SUM(CASE WHEN m.type = :debit THEN m.amount ELSE -m.amount END), 0)';

        $qb = $this->createQueryBuilder('m')
            ->select('IDENTITY(m.customer) AS customerId')
            ->addSelect($balanceExpression.' AS balance')
            ->addSelect('MAX(m.createdAt) AS lastMovement')
            ->andWhere('m.business = :business')
            ->groupBy('m.customer')
            ->having($balanceExpression.' > :minBalance')
            ->setParameter('business', $business)
            ->setParameter('debit', CustomerAccountMovement::TYPE_DEBIT)
            ->setParameter('minBalance', $minBalance);

        return $qb->getQuery()->getArrayResult();
    }
}
