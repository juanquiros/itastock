<?php

namespace App\Tests\Service;

use App\Entity\Product;
use App\Service\ProductSearchTextBuilder;
use PHPUnit\Framework\TestCase;

class ProductSearchTextBuilderTest extends TestCase
{
    public function testNormalizeRemovesAccentsAndSeparators(): void
    {
        $builder = new ProductSearchTextBuilder();

        self::assertSame('peugeot 208 der izq', $builder->normalize('Peugeot 208 Der-Izq'));
    }

    public function testTokenizeUsesNormalizedTokens(): void
    {
        $builder = new ProductSearchTextBuilder();

        self::assertSame(['peugeot', 'extremo', 'der', 'izq'], $builder->tokenize('Peugeot extremo der-izq'));
    }

    public function testBuildForProductIncludesCharacteristics(): void
    {
        $builder = new ProductSearchTextBuilder();
        $product = (new Product())
            ->setName('Extremo Der-Izq')
            ->setSku('SKU-123')
            ->setBarcode('77900112233')
            ->setCharacteristics([
                'lado' => 'derecho',
                'modelo' => '208',
            ]);

        $searchText = $builder->buildForProduct($product);

        self::assertStringContainsString('extremo der izq', $searchText);
        self::assertStringContainsString('sku 123', $searchText);
        self::assertStringContainsString('lado derecho', $searchText);
        self::assertStringContainsString('modelo 208', $searchText);
    }

    public function testBuildForProductReadsLegacyCharacteristicsShape(): void
    {
        $builder = new ProductSearchTextBuilder();
        $product = (new Product())
            ->setName('Pastilla freno')
            ->setSku('PF-10');

        $reflection = new \ReflectionProperty(Product::class, 'characteristics');
        $reflection->setAccessible(true);
        $reflection->setValue($product, [
            ['key' => 'marcaauto', 'value' => 'Peugeot'],
            ['key' => 'modelo', 'value' => '208'],
        ]);

        $searchText = $builder->buildForProduct($product);

        self::assertStringContainsString('marcaauto peugeot', $searchText);
        self::assertStringContainsString('modelo 208', $searchText);
    }

}
