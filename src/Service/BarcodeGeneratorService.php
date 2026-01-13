<?php

namespace App\Service;

use Picqer\Barcode\BarcodeGeneratorPNG;

class BarcodeGeneratorService
{
    public function generatePngDataUri(string $value, string $type = 'CODE128'): string
    {
        if ($value === '') {
            return '';
        }

        $generator = new BarcodeGeneratorPNG();
        $barcodeType = match ($type) {
            'EAN13' => $generator::TYPE_EAN_13,
            default => $generator::TYPE_CODE_128,
        };

        $data = $generator->getBarcode($value, $barcodeType);

        return sprintf('data:image/png;base64,%s', base64_encode($data));
    }
}
