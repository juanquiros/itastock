<?php

namespace App\Repository;

use App\Entity\Business;
use App\Entity\FiscalComponent;
use App\Entity\PurchaseInvoice;
use App\Entity\Sale;
use DateTimeInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class FiscalComponentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FiscalComponent::class);
    }

    public function findForSale(Sale $sale): array
    {
        return $this->findBy(['sale' => $sale], ['id' => 'ASC']);
    }

    public function findForPurchaseInvoice(PurchaseInvoice $purchaseInvoice): array
    {
        return $this->findBy(['purchaseInvoice' => $purchaseInvoice], ['id' => 'ASC']);
    }

    public function sumAmountForSale(Sale $sale): string
    {
        return (string) ($this->createQueryBuilder('f')
            ->select('COALESCE(SUM(f.amount), 0)')
            ->andWhere('f.sale = :sale')
            ->setParameter('sale', $sale)
            ->getQuery()
            ->getSingleScalarResult() ?? '0.00');
    }

    public function sumAmountForPurchaseInvoice(PurchaseInvoice $purchaseInvoice): string
    {
        return (string) ($this->createQueryBuilder('f')
            ->select('COALESCE(SUM(f.amount), 0)')
            ->andWhere('f.purchaseInvoice = :purchase')
            ->setParameter('purchase', $purchaseInvoice)
            ->getQuery()
            ->getSingleScalarResult() ?? '0.00');
    }

    public function findForBusinessReport(
        Business $business,
        ?DateTimeInterface $from = null,
        ?DateTimeInterface $to = null,
        ?string $sourceType = null,
        ?string $componentType = null
    ): array {
        $qb = $this->createQueryBuilder('f')
            ->leftJoin('f.sale', 's')->addSelect('s')
            ->leftJoin('f.purchaseInvoice', 'p')->addSelect('p')
            ->leftJoin('s.customer', 'c')->addSelect('c')
            ->leftJoin('p.supplier', 'sup')->addSelect('sup')
            ->andWhere('f.business = :business')
            ->setParameter('business', $business)
            ->orderBy('f.createdAt', 'DESC')
            ->addOrderBy('f.id', 'DESC');

        if ($from !== null) {
            $qb->andWhere('f.createdAt >= :from')
                ->setParameter('from', $from);
        }

        if ($to !== null) {
            $qb->andWhere('f.createdAt <= :to')
                ->setParameter('to', $to);
        }

        if ($sourceType !== null && $sourceType !== '') {
            $qb->andWhere('f.sourceType = :sourceType')
                ->setParameter('sourceType', $sourceType);
        }

        if ($componentType !== null && $componentType !== '') {
            $qb->andWhere('f.componentType = :componentType')
                ->setParameter('componentType', $componentType);
        }

        return $qb->getQuery()->getResult();
    }
}
