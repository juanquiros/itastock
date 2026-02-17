<?php

namespace App\Service;

use Picqer\Barcode\BarcodeGeneratorPNG;

class BarcodeGeneratorService
{
    public function generatePngDataUri(string $value, string $type = 'CODE128'): string
    {
        $cachedPath = $this->generatePngCachedFile($value, $type, 'var/cache/barcodes');
        if ($cachedPath === null || !is_file($cachedPath)) {
            return '';
        }

        $data = file_get_contents($cachedPath);
        if ($data === false) {
            return '';
        }

        return sprintf('data:image/png;base64,%s', base64_encode($data));
    }

    public function generatePngCachedFile(string $value, string $type, string $cacheDir): ?string
    {
        $value = trim($value);
        if ($value === '' || !extension_loaded('gd')) {
            return null;
        }

        $type = strtoupper($type);
        if ($type === 'EAN13' && preg_match('/^\d{13}$/', $value) !== 1) {
            return null;
        }

        $hash = sha1($type.'|'.$value);
        $cacheDir = trim(str_replace('\\', '/', $cacheDir), '/');
        if ($cacheDir === '') {
            $cacheDir = 'var/cache/barcodes';
        }

        if (!is_dir($cacheDir) && !mkdir($cacheDir, 0775, true) && !is_dir($cacheDir)) {
            return null;
        }

        $filePath = $cacheDir.'/'.$hash.'.png';
        if (is_file($filePath)) {
            return $filePath;
        }

        $generator = new BarcodeGeneratorPNG();
        $barcodeType = match ($type) {
            'EAN13' => $generator::TYPE_EAN_13,
            default => $generator::TYPE_CODE_128,
        };

        $png = $generator->getBarcode($value, $barcodeType);
        if (file_put_contents($filePath, $png) === false) {
            return null;
        }

        return $filePath;
    }
}
