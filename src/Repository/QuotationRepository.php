<?php

namespace App\Repository;

use App\Entity\Business;
use App\Entity\Quotation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
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

    public function nextCommercialSequence(Business $business): int
    {
        $maxId = $this->createQueryBuilder('q')
            ->select('MAX(q.id)')
            ->andWhere('q.business = :business')
            ->setParameter('business', $business)
            ->getQuery()
            ->getSingleScalarResult();

        return ((int) $maxId) + 1;
    }

    public function createSearchQueryBuilder(Business $business, ?string $query): QueryBuilder
    {
        $qb = $this->createQueryBuilder('q')
            ->leftJoin('q.customer', 'c')->addSelect('c')
            ->leftJoin('q.createdBy', 'u')->addSelect('u')
            ->andWhere('q.business = :business')
            ->setParameter('business', $business)
            ->orderBy('q.createdAt', 'DESC');

        $rawQuery = trim((string) $query);
        if ($rawQuery === '') {
            return $qb;
        }

        $parsedId = $this->parseCommercialOrNumericId($rawQuery);
        $qb->andWhere('LOWER(c.name) LIKE :term OR LOWER(u.fullName) LIKE :term OR q.id = :parsedId')
            ->setParameter('term', '%'.mb_strtolower($rawQuery).'%')
            ->setParameter('parsedId', $parsedId ?? 0);

        return $qb;
    }

    private function parseCommercialOrNumericId(string $query): ?int
    {
        $trimmed = trim($query);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/^pres-\\s*(\\d+)$/i', $trimmed, $matches) === 1) {
            return (int) ltrim($matches[1], '0') ?: 0;
        }

        if (preg_match('/^\\d+$/', $trimmed) === 1) {
            return (int) ltrim($trimmed, '0') ?: 0;
        }

        return null;
    }
}
