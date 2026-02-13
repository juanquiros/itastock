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
use Psr\Log\LoggerInterface;

class ArcaQrService
{
    private const BASE_URL = 'https://www.afip.gob.ar/fe/qr/';

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function buildQrUrl(ArcaInvoice|ArcaCreditNote $comprobante, ?BusinessArcaConfig $config): ?string
    {
        $payload = $comprobante instanceof ArcaInvoice
            ? $this->buildPayloadForInvoice($comprobante, $config)
            : $this->buildPayloadForCreditNote($comprobante, $config);

        if ($payload === []) {
            return null;
        }

        $this->logQrPayloadForSmokeTest($payload);

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $b64 = base64_encode((string) $json);
        $url = self::BASE_URL.'?p='.rawurlencode($b64);

        $this->logQrUrlVerification($url);

        return $url;
    }

    public function buildPayloadForInvoice(ArcaInvoice $invoice, ?BusinessArcaConfig $config): array
    {
        if (!$this->canBuildInvoicePayload($invoice, $config)) {
            return [];
        }

        $payload = [
            'ver' => 1,
            'fecha' => $invoice->getIssuedAt()?->format('Y-m-d'),
            'cuit' => (int) $this->normalizeDigits((string) $config?->getCuitEmisor()),
            'ptoVta' => $invoice->getArcaPosNumber(),
            'tipoCmp' => $invoice->getCbteTipo() === ArcaInvoice::CBTE_FACTURA_B ? 6 : 11,
            'nroCmp' => $invoice->getCbteNumero(),
            'importe' => (float) $invoice->getTotalAmount(),
            'moneda' => 'PES',
            'ctz' => 1,
            'tipoDocRec' => 99,
            'nroDocRec' => 0,
            'tipoCodAut' => 'E',
            'codAut' => $this->normalizeDigits((string) $invoice->getCae()),
        ];

        $this->appendReceiverDocument($payload, $invoice->getReceiverCustomer() ?? $invoice->getSale()?->getCustomer());

        return $payload;
    }

    public function buildPayloadForCreditNote(ArcaCreditNote $note, ?BusinessArcaConfig $config): array
    {
        if (!$this->canBuildCreditNotePayload($note, $config)) {
            return [];
        }

        $sale = $note->getSale();

        $payload = [
            'ver' => 1,
            'fecha' => $note->getIssuedAt()?->format('Y-m-d'),
            'cuit' => (int) $this->normalizeDigits((string) $config?->getCuitEmisor()),
            'ptoVta' => $note->getArcaPosNumber(),
            'tipoCmp' => $note->getCbteTipo() === ArcaCreditNote::CBTE_NC_B ? 8 : 13,
            'nroCmp' => $note->getCbteNumero(),
            'importe' => (float) $note->getTotalAmount(),
            'moneda' => 'PES',
            'ctz' => 1,
            'tipoDocRec' => 99,
            'nroDocRec' => 0,
            'tipoCodAut' => 'E',
            'codAut' => $this->normalizeDigits((string) $note->getCae()),
        ];

        $this->appendReceiverDocument($payload, $sale?->getCustomer());

        return $payload;
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

    private function canBuildInvoicePayload(ArcaInvoice $invoice, ?BusinessArcaConfig $config): bool
    {
        return $invoice->getCae() !== null
            && $invoice->getIssuedAt() !== null
            && $invoice->getCbteNumero() !== null
            && $invoice->getArcaPosNumber() > 0
            && $invoice->getCbteTipo() !== ''
            && $this->normalizeDigits((string) $config?->getCuitEmisor()) !== '';
    }

    private function canBuildCreditNotePayload(ArcaCreditNote $note, ?BusinessArcaConfig $config): bool
    {
        return $note->getCae() !== null
            && $note->getIssuedAt() !== null
            && $note->getCbteNumero() !== null
            && $note->getArcaPosNumber() > 0
            && $note->getCbteTipo() !== ''
            && $this->normalizeDigits((string) $config?->getCuitEmisor()) !== '';
    }


    /**
     * @param array<string, mixed> $payload
     */
    private function logQrPayloadForSmokeTest(array $payload): void
    {
        $appEnv = strtolower((string) ($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: ''));
        if (!in_array($appEnv, ['dev', 'test'], true)) {
            return;
        }

        $this->logger->debug('ARCA QR payload smoke', [
            'has_cuit' => isset($payload['cuit']) && (string) $payload['cuit'] !== '',
            'has_codAut' => isset($payload['codAut']) && (string) $payload['codAut'] !== '',
            'payload' => $payload,
        ]);
    }


    private function logQrUrlVerification(string $url): void
    {
        $appEnv = strtolower((string) ($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: ''));
        if (!in_array($appEnv, ['dev', 'test'], true)) {
            return;
        }

        $query = parse_url($url, PHP_URL_QUERY);
        if (!is_string($query)) {
            return;
        }

        parse_str($query, $params);
        $p = isset($params['p']) ? (string) $params['p'] : '';
        if ($p === '') {
            return;
        }

        $decodedJson = base64_decode(rawurldecode($p), true);
        if ($decodedJson === false) {
            return;
        }

        $decodedPayload = json_decode($decodedJson, true);
        if (!is_array($decodedPayload)) {
            return;
        }

        $this->logger->debug('ARCA QR URL decoded payload', [
            'json' => $decodedPayload,
            'cuit_is_numeric' => isset($decodedPayload['cuit']) && is_int($decodedPayload['cuit']),
            'codAut_digits_only' => isset($decodedPayload['codAut']) && preg_match('/^\d+$/', (string) $decodedPayload['codAut']) === 1,
        ]);
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

        $docTypeCode = $this->mapReceiverDocType((string) $customer->getDocumentType());
        $payload['tipoDocRec'] = $docTypeCode;
        $payload['nroDocRec'] = $docTypeCode === 99 ? 0 : (int) $docNumber;
    }

    private function mapReceiverDocType(string $docType): int
    {
        return match (strtoupper(trim($docType))) {
            'CUIT' => 80,
            'CUIL' => 86,
            'CDI' => 87,
            'DNI', 'DOCUMENTO', 'LE', 'LC' => 96,
            'CI EXTRANJERA', 'CI' => 91,
            'PASAPORTE' => 94,
            default => 99,
        };
    }
}
