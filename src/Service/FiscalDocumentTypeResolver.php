<?php
namespace App\Service;

use App\Entity\ArcaInvoice;
use App\Entity\BusinessArcaConfig;

class FiscalDocumentTypeResolver
{
    public function resolve(BusinessArcaConfig $config, ?int $receiverIvaConditionId): string
    {
        if ($config->getTaxPayerType() === BusinessArcaConfig::TAX_PAYER_MONOTRIBUTO) {
            return ArcaInvoice::CBTE_FACTURA_C;
        }
        if ($receiverIvaConditionId === 1) {
            return ArcaInvoice::CBTE_FACTURA_A;
        }

        return ArcaInvoice::CBTE_FACTURA_B;
    }
}
