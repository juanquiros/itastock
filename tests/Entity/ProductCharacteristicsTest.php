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
}
