<?php

namespace App\Service;

use App\Entity\Product;

class ProductSearchTextBuilder
{
    public function buildForProduct(Product $product): string
    {
        $parts = [
            $product->getName(),
            $product->getSku(),
            $product->getBarcode(),
            $product->getCategory()?->getName(),
            $product->getBrand()?->getName(),
            $product->getCatalogProduct()?->getPresentation(),
            $product->getCatalogProduct()?->getName(),
        ];

        foreach ($product->getCharacteristics() as $key => $value) {
            $parts[] = (string) $key;
            $parts[] = (string) $value;
        }

        return $this->normalize(implode(' ', array_filter($parts, static fn (?string $value): bool => $value !== null && trim($value) !== '')));
    }

    public function normalize(string $value): string
    {
        $normalized = mb_strtolower(trim($value));
        $normalized = strtr($normalized, [
            'á' => 'a',
            'é' => 'e',
            'í' => 'i',
            'ó' => 'o',
            'ú' => 'u',
            'ü' => 'u',
            'ñ' => 'n',
        ]);
        $normalized = str_replace(['-', '_'], ' ', $normalized);

        return (string) preg_replace('/\s+/', ' ', $normalized);
    }

    /**
     * @return string[]
     */
    public function tokenize(string $query): array
    {
        $tokens = array_filter(explode(' ', $this->normalize($query)), static fn (string $token): bool => $token !== '');

        return array_values(array_unique($tokens));
    }
}
