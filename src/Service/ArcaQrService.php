<?php

namespace App\Service;

use App\Entity\ArcaCreditNote;
use App\Entity\ArcaInvoice;
use App\Entity\BusinessArcaConfig;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;

class ArcaQrService
{
    private const BASE_URL = 'https://www.arca.gob.ar/fe/qr/';

    public function buildPayloadForInvoice(ArcaInvoice $invoice, ?BusinessArcaConfig $config): array
    {
        if ($invoice->getCae() === null || $invoice->getCbteNumero() === null || $config?->getCuitEmisor() === null) {
            return [];
        }

        $payload = [
            'ver' => 1,
            'fecha' => ($invoice->getIssuedAt() ?? $invoice->getSale()?->getCreatedAt() ?? new \DateTimeImmutable())->format('Y-m-d'),
            'cuit' => $this->normalizeDigits($config->getCuitEmisor()),
            'ptoVta' => $invoice->getArcaPosNumber(),
            'tipoCmp' => $invoice->getCbteTipo() === ArcaInvoice::CBTE_FACTURA_B ? 6 : 11,
            'nroCmp' => $invoice->getCbteNumero(),
            'importe' => (float) $invoice->getTotalAmount(),
            'moneda' => 'PES',
            'ctz' => 1,
            'tipoCodAut' => 'E',
            'codAut' => $invoice->getCae(),
        ];

        $this->appendReceiverDocument($payload, $invoice->getReceiverCustomer() ?? $invoice->getSale()?->getCustomer());

        return $payload;
    }

    public function buildPayloadForCreditNote(ArcaCreditNote $note, ?BusinessArcaConfig $config): array
    {
        if ($note->getCae() === null || $note->getCbteNumero() === null || $config?->getCuitEmisor() === null) {
            return [];
        }

        $sale = $note->getSale();

        $payload = [
            'ver' => 1,
            'fecha' => ($note->getIssuedAt() ?? $sale?->getCreatedAt() ?? new \DateTimeImmutable())->format('Y-m-d'),
            'cuit' => $this->normalizeDigits($config->getCuitEmisor()),
            'ptoVta' => $note->getArcaPosNumber(),
            'tipoCmp' => $note->getCbteTipo() === ArcaCreditNote::CBTE_NC_B ? 8 : 13,
            'nroCmp' => $note->getCbteNumero(),
            'importe' => (float) $note->getTotalAmount(),
            'moneda' => 'PES',
            'ctz' => 1,
            'tipoCodAut' => 'E',
            'codAut' => $note->getCae(),
        ];

        $this->appendReceiverDocument($payload, $sale?->getCustomer());

        return $payload;
    }

    public function buildQrUrl(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $b64 = base64_encode((string) $json);

        return self::BASE_URL.'?p='.urlencode($b64);
    }

    public function generatePngDataUri(string $qrUrl): string
    {
        $result = Builder::create()
            ->writer(new PngWriter())
            ->data($qrUrl)
            ->encoding(new Encoding('UTF-8'))
            ->errorCorrectionLevel(ErrorCorrectionLevel::Low)
            ->size(220)
            ->margin(8)
            ->roundBlockSizeMode(RoundBlockSizeMode::Margin)
            ->build();

        return 'data:image/png;base64,'.base64_encode($result->getString());
    }

    private function normalizeDigits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    private function appendReceiverDocument(array &$payload, mixed $customer): void
    {
        if ($customer === null) {
            return;
        }

        $docNumber = $this->normalizeDigits((string) $customer->getDocumentNumber());
        if ($docNumber === '') {
            return;
        }

        $docType = strtoupper((string) $customer->getDocumentType());
        if ($docType === 'CUIT') {
            $payload['tipoDocRec'] = 80;
            $payload['nroDocRec'] = (int) $docNumber;
            return;
        }

        if ($docType === 'DNI') {
            $payload['tipoDocRec'] = 96;
            $payload['nroDocRec'] = (int) $docNumber;
        }
    }
}
