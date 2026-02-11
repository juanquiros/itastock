<?php

namespace App\Tests\Entity;

use App\Entity\Product;
use PHPUnit\Framework\TestCase;

class ProductCharacteristicsTest extends TestCase
{
    public function testGetCharacteristicsSupportsLegacyListShape(): void
    {
        $product = new Product();
        $reflection = new \ReflectionProperty(Product::class, 'characteristics');
        $reflection->setAccessible(true);
        $reflection->setValue($product, [
            ['key' => 'marcaauto', 'value' => 'Peugeot'],
            ['key' => 'lado', 'value' => 'derecho'],
        ]);

        self::assertSame([
            'marcaauto' => 'Peugeot',
            'lado' => 'derecho',
        ], $product->getCharacteristics());
    }

    public function testRebuildSearchTextIncludesCharacteristics(): void
    {
        $product = (new Product())
            ->setName('Extremo Der-Izq')
            ->setSku('SKU-001')
            ->setBarcode('779123')
            ->setCharacteristics([
                ['key' => 'marcaauto', 'value' => 'Peugeot'],
                ['key' => 'modelo', 'value' => '208'],
            ]);

        $product->rebuildSearchTextIndex();

        self::assertNotNull($product->getSearchText());
        self::assertStringContainsString('extremo der izq', (string) $product->getSearchText());
        self::assertStringContainsString('marcaauto peugeot', (string) $product->getSearchText());
        self::assertStringContainsString('modelo 208', (string) $product->getSearchText());
    }


    public function testCharacteristicsSummaryIsGenerated(): void
    {
        $product = (new Product())->setCharacteristics([
            'marcaauto' => 'Peugeot',
            'modelo' => '208',
        ]);

        self::assertSame('marcaauto: Peugeot · modelo: 208', $product->getCharacteristicsSummary());
    }

}
