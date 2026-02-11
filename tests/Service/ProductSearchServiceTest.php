<?php

namespace App\Tests\Service;

use App\Entity\Business;
use App\Entity\Product;
use App\Repository\ProductRepository;
use App\Security\BusinessContext;
use App\Service\ProductSearchService;
use App\Service\ProductSearchTextBuilder;
use PHPUnit\Framework\TestCase;

class ProductSearchServiceTest extends TestCase
{
    public function testBarcodeExactMatchHasTopPriority(): void
    {
        $business = $this->createStub(Business::class);
        $product = (new Product())->setName('Producto A')->setSku('A1');

        $repository = $this->createMock(ProductRepository::class);
        $repository->expects(self::once())->method('findOneByBusinessAndExactBarcode')->willReturn($product);
        $repository->expects(self::never())->method('findOneByBusinessAndExactSku');

        $service = $this->buildService($repository, $business);

        self::assertSame([$product], $service->search('77900112233'));
    }

    public function testSkuExactMatchIsSecondPriority(): void
    {
        $business = $this->createStub(Business::class);
        $product = (new Product())->setName('Producto B')->setSku('SKU-01');

        $repository = $this->createMock(ProductRepository::class);
        $repository->method('findOneByBusinessAndExactBarcode')->willReturn(null);
        $repository->expects(self::once())->method('findOneByBusinessAndExactSku')->willReturn($product);
        $repository->expects(self::never())->method('findByBusinessAndNameLike');

        $service = $this->buildService($repository, $business);

        self::assertSame([$product], $service->search('SKU-01'));
    }

    public function testNameLikeMatchIsThirdPriority(): void
    {
        $business = $this->createStub(Business::class);
        $product = (new Product())->setName('Peugeot extremo')->setSku('SKU-02');

        $repository = $this->createMock(ProductRepository::class);
        $repository->method('findOneByBusinessAndExactBarcode')->willReturn(null);
        $repository->method('findOneByBusinessAndExactSku')->willReturn(null);
        $repository->expects(self::once())->method('findByBusinessAndNameLike')->willReturn([$product]);
        $repository->expects(self::never())->method('findByBusinessAndSearchTokens');

        $service = $this->buildService($repository, $business);

        self::assertSame([$product], $service->search('peugeot'));
    }

    public function testTokenSearchRunsLast(): void
    {
        $business = $this->createStub(Business::class);
        $product = (new Product())->setName('Extremo')->setSku('SKU-03');

        $repository = $this->createMock(ProductRepository::class);
        $repository->method('findOneByBusinessAndExactBarcode')->willReturn(null);
        $repository->method('findOneByBusinessAndExactSku')->willReturn(null);
        $repository->method('findByBusinessAndNameLike')->willReturn([]);
        $repository->expects(self::once())->method('findByBusinessAndSearchTokens')->with($business, ['peugeot', 'der', 'izq'], 50)->willReturn([$product]);

        $service = $this->buildService($repository, $business);

        self::assertSame([$product], $service->search('Peugeot der-izq'));
    }

    private function buildService(ProductRepository $repository, Business $business): ProductSearchService
    {
        $context = $this->createMock(BusinessContext::class);
        $context->method('getCurrentBusiness')->willReturn($business);

        return new ProductSearchService($repository, $context, new ProductSearchTextBuilder());
    }
}
