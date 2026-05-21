<?php
namespace App\Repository;

use App\Entity\FiscalComponent;
use App\Entity\PurchaseInvoice;
use App\Entity\Sale;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class FiscalComponentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, FiscalComponent::class); }
    public function findForSale(Sale $sale): array { return $this->findBy(['sale'=>$sale], ['id'=>'ASC']); }
    public function findForPurchaseInvoice(PurchaseInvoice $purchaseInvoice): array { return $this->findBy(['purchaseInvoice'=>$purchaseInvoice], ['id'=>'ASC']); }
    public function sumAmountForSale(Sale $sale): string { return (string)($this->createQueryBuilder('f')->select('COALESCE(SUM(f.amount), 0)')->andWhere('f.sale = :sale')->setParameter('sale',$sale)->getQuery()->getSingleScalarResult() ?? '0.00'); }
    public function sumAmountForPurchaseInvoice(PurchaseInvoice $purchaseInvoice): string { return (string)($this->createQueryBuilder('f')->select('COALESCE(SUM(f.amount), 0)')->andWhere('f.purchaseInvoice = :purchase')->setParameter('purchase',$purchaseInvoice)->getQuery()->getSingleScalarResult() ?? '0.00'); }
}
