<?php

namespace App\Repository;

use App\Entity\Business;
use App\Entity\Quotation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Quotation>
 */
class QuotationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Quotation::class);
    }

    /**
     * @return array{items: array<int, Quotation>, total: int}
     */
    public function findForBusinessPaginated(Business $business, ?string $q, int $page, int $pageSize): array
    {
        $page = max(1, $page);
        $pageSize = max(1, min(100, $pageSize));
        $query = trim((string) $q);

        $qb = $this->createQueryBuilder('q')
            ->leftJoin('q.customer', 'c')
            ->addSelect('c')
            ->leftJoin('q.createdBy', 'u')
            ->addSelect('u')
            ->andWhere('q.business = :business')
            ->setParameter('business', $business)
            ->orderBy('q.createdAt', 'DESC');

        if ($query !== '') {
            $expr = $qb->expr()->orX(
                'c.name LIKE :term',
                'q.id = :exactId'
            );

            $date = \DateTimeImmutable::createFromFormat('d/m/Y', $query);
            if ($date instanceof \DateTimeImmutable) {
                $expr->add('q.createdAt BETWEEN :startDate AND :endDate');
                $qb
                    ->setParameter('startDate', $date->setTime(0, 0, 0))
                    ->setParameter('endDate', $date->setTime(23, 59, 59));
            }

            $qb
                ->andWhere($expr)
                ->setParameter('term', '%'.$query.'%')
                ->setParameter('exactId', ctype_digit($query) ? (int) $query : 0);
        }

        $countQb = clone $qb;
        $total = (int) $countQb
            ->select('COUNT(q.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()
            ->getSingleScalarResult();

        $items = $qb
            ->setFirstResult(($page - 1) * $pageSize)
            ->setMaxResults($pageSize)
            ->getQuery()
            ->getResult();

        return [
            'items' => $items,
            'total' => $total,
        ];
    }
}
