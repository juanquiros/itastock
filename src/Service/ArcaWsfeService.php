<?php

namespace App\Service;

use App\Entity\ArcaInvoice;
use App\Entity\BusinessArcaConfig;
use DateTimeImmutable;

class ArcaWsfeService
{
    public function __construct(
        private readonly string $arcaWsfeWsdlHomo,
        private readonly string $arcaWsfeWsdlProd,
    ) {
    }

    /**
     * @param array{token: string, sign: string} $tokenSign
     * @return array{request: array, response: array, cbteNumero: int, cae: ?string, caeDueDate: ?DateTimeImmutable}
     */
    public function requestCae(ArcaInvoice $invoice, BusinessArcaConfig $config, array $tokenSign): array
    {
        $wsdl = $config->getArcaEnvironment() === BusinessArcaConfig::ENV_PROD ? $this->arcaWsfeWsdlProd : $this->arcaWsfeWsdlHomo;
        if ($wsdl === '') {
            throw new \RuntimeException('WSDL de WSFE no configurado.');
        }

        $client = new \SoapClient($wsdl, [
            'trace' => 1,
            'exceptions' => true,
        ]);

        $auth = [
            'Token' => $tokenSign['token'],
            'Sign' => $tokenSign['sign'],
            'Cuit' => $config->getCuitEmisor(),
        ];

        $cbteTipo = $this->resolveCbteTipoCode($invoice->getCbteTipo());
        $last = $client->FECompUltimoAutorizado([
            'Auth' => $auth,
            'PtoVta' => $invoice->getArcaPosNumber(),
            'CbteTipo' => $cbteTipo,
        ]);

        $lastNumber = (int) ($last->FECompUltimoAutorizadoResult->CbteNro ?? 0);
        $cbteNumero = $lastNumber + 1;

        $issuedAt = $invoice->getIssuedAt() ?? new DateTimeImmutable();

        $detail = [
            'Concepto' => 1,
            'DocTipo' => 99,
            'DocNro' => 0,
            'CbteDesde' => $cbteNumero,
            'CbteHasta' => $cbteNumero,
            'CbteFch' => $issuedAt->format('Ymd'),
            'ImpTotal' => (float) $invoice->getTotalAmount(),
            'ImpTotConc' => 0,
            'ImpNeto' => (float) $invoice->getNetAmount(),
            'ImpOpEx' => 0,
            'ImpIVA' => (float) $invoice->getVatAmount(),
            'ImpTrib' => 0,
            'MonId' => 'PES',
            'MonCotiz' => 1,
        ];

        $ivaItems = $this->buildIvaItems($invoice);
        if ($ivaItems) {
            $detail['Iva'] = [
                'AlicIva' => $ivaItems,
            ];
        }

        $request = [
            'Auth' => $auth,
            'FeCAEReq' => [
                'FeCabReq' => [
                    'CantReg' => 1,
                    'PtoVta' => $invoice->getArcaPosNumber(),
                    'CbteTipo' => $cbteTipo,
                ],
                'FeDetReq' => [
                    'FECAEDetRequest' => $detail,
                ],
            ],
        ];

        $response = $client->FECAESolicitar($request);
        $responseArray = json_decode(json_encode($response, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        $detailResponse = $response->FECAESolicitarResult->FeDetResp->FECAEDetResponse ?? null;
        $cae = $detailResponse->CAE ?? null;
        $caeDue = $detailResponse->CAEFchVto ?? null;

        return [
            'request' => $request,
            'response' => $responseArray,
            'cbteNumero' => $cbteNumero,
            'cae' => $cae ? (string) $cae : null,
            'caeDueDate' => $caeDue ? DateTimeImmutable::createFromFormat('Ymd', (string) $caeDue) ?: null : null,
        ];
    }

    private function resolveCbteTipoCode(string $cbteTipo): int
    {
        return match ($cbteTipo) {
            ArcaInvoice::CBTE_FACTURA_B => 6,
            ArcaInvoice::CBTE_FACTURA_C => 11,
            default => 11,
        };
    }

    /**
     * @return array<int, array{id: int, BaseImp: float, Importe: float}>
     */
    private function buildIvaItems(ArcaInvoice $invoice): array
    {
        $items = $invoice->getItemsSnapshot() ?? [];
        if ($invoice->getVatAmount() === '0.00' || !$items) {
            return [];
        }

        $totals = [];
        foreach ($items as $item) {
            $rate = isset($item['ivaRate']) ? (float) $item['ivaRate'] : 21.0;
            $lineTotal = isset($item['lineTotal']) ? (float) $item['lineTotal'] : 0.0;
            if ($rate <= 0 || $lineTotal <= 0) {
                continue;
            }

            $net = $lineTotal / (1 + ($rate / 100));
            $vat = $lineTotal - $net;

            if (!isset($totals[$rate])) {
                $totals[$rate] = ['net' => 0.0, 'vat' => 0.0];
            }

            $totals[$rate]['net'] += $net;
            $totals[$rate]['vat'] += $vat;
        }

        $result = [];
        foreach ($totals as $rate => $values) {
            $result[] = [
                'Id' => $this->resolveIvaId((float) $rate),
                'BaseImp' => round($values['net'], 2),
                'Importe' => round($values['vat'], 2),
            ];
        }

        return $result;
    }

    private function resolveIvaId(float $rate): int
    {
        return match ((string) $rate) {
            '10.5' => 4,
            '21', '21.0', '21.00' => 5,
            '27', '27.0', '27.00' => 6,
            '0', '0.0', '0.00' => 3,
            default => 5,
        };
    }
}
