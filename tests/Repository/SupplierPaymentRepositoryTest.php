<?php

namespace App\Tests\Repository;

use App\Entity\Business;
use App\Repository\SupplierPaymentRepository;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;

class SupplierPaymentRepositoryTest extends TestCase
{
    public function testAggregateTotalsByMethodFormatsByMethod(): void
    {
        $business = new Business();

        $query = $this->createMock(AbstractQuery::class);
        $query->expects(self::once())->method('getArrayResult')->willReturn([
            ['method' => 'CASH', 'total' => '1500.5'],
            ['method' => 'TRANSFER', 'total' => '2300'],
        ]);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('groupBy')->willReturnSelf();
        $qb->expects(self::once())->method('getQuery')->willReturn($query);

        $repository = $this->buildRepository($qb);

        $result = $repository->aggregateTotalsByMethod(
            $business,
            new \DateTimeImmutable('2026-04-09 00:00:00'),
            new \DateTimeImmutable('2026-04-09 23:59:59')
        );

        self::assertSame([
            'CASH' => '1500.50',
            'TRANSFER' => '2300.00',
        ], $result);
    }

    private function buildRepository(QueryBuilder $qb): SupplierPaymentRepository
    {
        $metadata = new ClassMetadata('App\\Entity\\SupplierPayment');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getClassMetadata')->willReturn($metadata);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->with('App\\Entity\\SupplierPayment')->willReturn($entityManager);

        return new class($registry, $qb) extends SupplierPaymentRepository {
            public function __construct(ManagerRegistry $registry, private readonly QueryBuilder $qb)
            {
                parent::__construct($registry);
            }

            public function createQueryBuilder(string $alias, ?string $indexBy = null): QueryBuilder
            {
                return $this->qb;
            }
        };
    }
}
