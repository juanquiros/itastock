<?php

namespace App\Service;

use Knp\Snappy\Pdf;

class WkhtmlPdfService
{
    public function __construct(private readonly Pdf $pdf)
    {
    }

    public function generateFromHtml(string $html, string $outputPath): void
    {
        $this->pdf->generateFromHtml($html, $outputPath);
    }
}
