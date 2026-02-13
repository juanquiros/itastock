<?php

namespace App\Service;

use App\Entity\ArcaCreditNote;
use App\Entity\ArcaInvoice;
use App\Entity\BusinessArcaConfig;
use DateTimeImmutable;

class ArcaWsfeService
{
    /**
     * Fallback local para entornos donde el catálogo WSFE no responde (ej. intermitencia en prod ARCA).
     *
     * @var array<int, string>
     */
    private const FALLBACK_CONDICION_IVA_RECEPTOR_OPTIONS = [
        1 => 'IVA Responsable Inscripto',
        4 => 'IVA Sujeto Exento',
        5 => 'Consumidor Final',
        6 => 'Responsable Monotributo',
        7 => 'Sujeto no Categorizado',
        8 => 'Proveedor del Exterior',
        9 => 'Cliente del Exterior',
        10 => 'IVA Liberado - Ley N° 19.640',
        13 => 'Monotributista Social',
        15 => 'IVA No Alcanzado',
        16 => 'Monotributo Trabajador Independiente Promovido',
    ];

    private const WSFE_URI = 'http://ar.gov.afip.dif.FEV1/';
    private const WSFE_LOCATION_PROD = 'https://servicios1.afip.gov.ar/wsfev1/service.asmx';
    private const WSFE_LOCATION_HOMO = 'https://wswhomo.afip.gov.ar/wsfev1/service.asmx';

    /**
     * @var array<int, array<int, string>>
     */
    private array $condicionIvaReceptorOptions = [];

    /**
     * @var array<int, string>
     */
    private array $condicionIvaReceptorErrors = [];

    public function __construct(
        private readonly ArcaWsaaService $wsaaService,
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
        $client = $this->createWsfeClient($config);

        $auth = [
            'Token' => $tokenSign['token'],
            'Sign' => $tokenSign['sign'],
            'Cuit' => $config->getCuitEmisor(),
        ];

        $cbteTipo = $this->resolveCbteTipoCode($invoice->getCbteTipo());
        $last = $this->callWsfe($client, 'FECompUltimoAutorizado', [
            'Auth' => $auth,
            'PtoVta' => $invoice->getArcaPosNumber(),
            'CbteTipo' => $cbteTipo,
        ]);

        $lastNumber = (int) ($last->FECompUltimoAutorizadoResult->CbteNro ?? 0);
        $cbteNumero = $lastNumber + 1;

        $issuedAt = $invoice->getIssuedAt() ?? new DateTimeImmutable();
        $receiverIvaConditionId = $invoice->getReceiverIvaConditionId();
        if ($receiverIvaConditionId === null) {
            throw new \RuntimeException('Condición IVA del receptor no configurada.');
        }

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
            'CondicionIVAReceptorId' => $receiverIvaConditionId,
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
                    'FECAEDetRequest' => [$detail],
                ],
            ],
        ];

        $response = $this->callWsfe($client, 'FECAESolicitar', $request);
        $responseArray = json_decode(json_encode($response, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        $detailResponse = $response->FECAESolicitarResult->FeDetResp->FECAEDetResponse ?? null;
        $cae = $detailResponse->CAE ?? null;
        $caeDue = $detailResponse->CAEFchVto ?? null;

        $responseArray['_soap_last_request'] = $client->__getLastRequest();
        $responseArray['_soap_last_response'] = $client->__getLastResponse();

        return [
            'request' => $request,
            'response' => $responseArray,
            'cbteNumero' => $cbteNumero,
            'cae' => $cae ? (string) $cae : null,
            'caeDueDate' => $caeDue ? DateTimeImmutable::createFromFormat('Ymd', (string) $caeDue) ?: null : null,
        ];
    }

    /**
     * @param array{token: string, sign: string} $tokenSign
     * @return array{request: array, response: array, cbteNumero: int, cae: ?string, caeDueDate: ?DateTimeImmutable}
     */
    public function requestCaeForCreditNote(
        ArcaCreditNote $note,
        BusinessArcaConfig $config,
        array $tokenSign,
        ArcaInvoice $associatedInvoice
    ): array {
        $client = $this->createWsfeClient($config);

        $auth = [
            'Token' => $tokenSign['token'],
            'Sign' => $tokenSign['sign'],
            'Cuit' => $config->getCuitEmisor(),
        ];

        $cbteTipo = $this->resolveCbteTipoCode($note->getCbteTipo());
        $last = $this->callWsfe($client, 'FECompUltimoAutorizado', [
            'Auth' => $auth,
            'PtoVta' => $note->getArcaPosNumber(),
            'CbteTipo' => $cbteTipo,
        ]);

        $lastNumber = (int) ($last->FECompUltimoAutorizadoResult->CbteNro ?? 0);
        $cbteNumero = $lastNumber + 1;

        $issuedAt = $note->getIssuedAt() ?? new DateTimeImmutable();
        $receiverIvaConditionId = $associatedInvoice->getReceiverIvaConditionId();
        if ($receiverIvaConditionId === null) {
            throw new \RuntimeException('Condición IVA del receptor no configurada.');
        }

        $detail = [
            'Concepto' => 1,
            'DocTipo' => 99,
            'DocNro' => 0,
            'CbteDesde' => $cbteNumero,
            'CbteHasta' => $cbteNumero,
            'CbteFch' => $issuedAt->format('Ymd'),
            'ImpTotal' => (float) $note->getTotalAmount(),
            'ImpTotConc' => 0,
            'ImpNeto' => (float) $note->getNetAmount(),
            'ImpOpEx' => 0,
            'ImpIVA' => (float) $note->getVatAmount(),
            'ImpTrib' => 0,
            'MonId' => 'PES',
            'MonCotiz' => 1,
            'CondicionIVAReceptorId' => $receiverIvaConditionId,
            'CbtesAsoc' => [
                'CbteAsoc' => [
                    'Tipo' => $this->resolveCbteTipoCode($associatedInvoice->getCbteTipo()),
                    'PtoVta' => $associatedInvoice->getArcaPosNumber(),
                    'Nro' => $associatedInvoice->getCbteNumero() ?? 0,
                ],
            ],
        ];

        $ivaItems = $this->buildIvaItemsFromSnapshot($note->getItemsSnapshot(), $note->getVatAmount());
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
                    'PtoVta' => $note->getArcaPosNumber(),
                    'CbteTipo' => $cbteTipo,
                ],
                'FeDetReq' => [
                    'FECAEDetRequest' => [$detail],
                ],
            ],
        ];

        $response = $this->callWsfe($client, 'FECAESolicitar', $request);
        $responseArray = json_decode(json_encode($response, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        $detailResponse = $response->FECAESolicitarResult->FeDetResp->FECAEDetResponse ?? null;
        $cae = $detailResponse->CAE ?? null;
        $caeDue = $detailResponse->CAEFchVto ?? null;

        $responseArray['_soap_last_request'] = $client->__getLastRequest();
        $responseArray['_soap_last_response'] = $client->__getLastResponse();

        return [
            'request' => $request,
            'response' => $responseArray,
            'cbteNumero' => $cbteNumero,
            'cae' => $cae ? (string) $cae : null,
            'caeDueDate' => $caeDue ? DateTimeImmutable::createFromFormat('Ymd', (string) $caeDue) ?: null : null,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function getWsfeWsdls(BusinessArcaConfig $config): array
    {
        $environment = $config->getArcaEnvironment();
        $configured = $environment === BusinessArcaConfig::ENV_PROD ? $this->arcaWsfeWsdlProd : $this->arcaWsfeWsdlHomo;
        $defaults = $environment === BusinessArcaConfig::ENV_PROD
            ? [
                'https://wsfev1.afip.gov.ar/wsfev1/service.asmx?wsdl',
                'https://wsfev1.afip.gov.ar/wsfev1/service.asmx?WSDL',
            ]
            : [
                'https://wswhomo.afip.gov.ar/wsfev1/service.asmx?wsdl',
                'https://wswhomo.afip.gov.ar/wsfev1/service.asmx?WSDL',
            ];

        $all = array_values(array_filter(array_unique(array_merge([$configured], $defaults)), static fn (string $url): bool => trim($url) !== ''));

        if ($all === []) {
            throw new \RuntimeException('WSDL de WSFE no configurado.');
        }

        return $all;
    }

    private function createWsfeClient(BusinessArcaConfig $config): \SoapClient
    {
        $errors = [];
        $streamContext = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'SNI_enabled' => true,
            ],
            'http' => [
                'timeout' => 20,
                'user_agent' => 'ItaStock-ARCA-WSFE/1.0',
            ],
        ]);

        $baseOptions = [
            'trace' => 1,
            'exceptions' => true,
            'connection_timeout' => 20,
            'stream_context' => $streamContext,
            'encoding' => 'UTF-8',
        ];

        foreach ($this->getWsfeWsdls($config) as $wsdl) {
            try {
                return new \SoapClient($wsdl, $baseOptions + [
                    'cache_wsdl' => WSDL_CACHE_MEMORY,
                ]);
            } catch (\Throwable $exception) {
                $errors[] = sprintf('%s => %s', $wsdl, $exception->getMessage());
            }
        }

        $location = $config->getArcaEnvironment() === BusinessArcaConfig::ENV_PROD
            ? self::WSFE_LOCATION_PROD
            : self::WSFE_LOCATION_HOMO;

        try {
            error_log(sprintf('[ARCA] WSDL WSFE inaccesible. Se usa cliente non-WSDL. env=%s errors=%s', $config->getArcaEnvironment(), implode(' | ', array_slice($errors, 0, 3))));

            return new \SoapClient(null, $baseOptions + [
                'location' => $location,
                'uri' => self::WSFE_URI,
                'soap_version' => SOAP_1_2,
                'cache_wsdl' => WSDL_CACHE_NONE,
            ]);
        } catch (\Throwable $fallbackException) {
            $errors[] = sprintf('non-wsdl %s => %s', $location, $fallbackException->getMessage());
        }

        throw new \RuntimeException('No se pudo inicializar WSFE ARCA (WSDL y non-WSDL). '.implode(' | ', array_slice($errors, 0, 4)));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function callWsfe(\SoapClient $client, string $method, array $payload): mixed
    {
        if ($method === 'FECAESolicitar') {
            $wrappedPayload = new \SoapVar(
                $payload,
                SOAP_ENC_OBJECT,
                null,
                null,
                $method,
                self::WSFE_URI
            );

            return $client->__soapCall(
                $method,
                [$wrappedPayload],
                [
                    'soapaction' => self::WSFE_URI.$method,
                    'uri' => self::WSFE_URI,
                ]
            );
        }

        if (!$this->isNonWsdlClient($client)) {
            return $client->{$method}($payload);
        }

        return $client->__soapCall(
            $method,
            $this->buildNonWsdlArguments($method, $payload),
            ['soapaction' => self::WSFE_URI.$method]
        );
    }

    private function isNonWsdlClient(\SoapClient $client): bool
    {
        try {
            $functions = $client->__getFunctions();

            return !is_array($functions) || $functions === [];
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<int, mixed>
     */
    private function buildNonWsdlArguments(string $method, array $payload): array
    {
        return match ($method) {
            'FECompUltimoAutorizado' => [
                new \SoapParam($payload['Auth'] ?? null, 'Auth'),
                new \SoapParam($payload['PtoVta'] ?? null, 'PtoVta'),
                new \SoapParam($payload['CbteTipo'] ?? null, 'CbteTipo'),
            ],
            'FEParamGetCondicionIvaReceptor' => [
                new \SoapParam($payload['Auth'] ?? null, 'Auth'),
            ],
            default => [new \SoapParam($payload, 'parameters')],
        };
    }

    private function resolveCbteTipoCode(string $cbteTipo): int
    {
        return match ($cbteTipo) {
            ArcaInvoice::CBTE_FACTURA_B => 6,
            ArcaInvoice::CBTE_FACTURA_C => 11,
            ArcaCreditNote::CBTE_NC_B => 8,
            ArcaCreditNote::CBTE_NC_C => 13,
            default => 11,
        };
    }

    /**
     * @return array<int, array{id: int, BaseImp: float, Importe: float}>
     */
    private function buildIvaItems(ArcaInvoice $invoice): array
    {
        return $this->buildIvaItemsFromSnapshot($invoice->getItemsSnapshot(), $invoice->getVatAmount());
    }

    /**
     * @return array<int, array{id: int, BaseImp: float, Importe: float}>
     */
    private function buildIvaItemsFromSnapshot(?array $itemsSnapshot, string $vatAmount): array
    {
        $items = $itemsSnapshot ?? [];
        if ($vatAmount === '0.00' || !$items) {
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

    /**
     * @return array<int, string>
     */
    public function getCondicionIvaReceptorOptions(BusinessArcaConfig $config): array
    {
        $businessId = $config->getBusiness()?->getId() ?? 0;
        if (array_key_exists($businessId, $this->condicionIvaReceptorOptions)) {
            return $this->condicionIvaReceptorOptions[$businessId];
        }

        $this->condicionIvaReceptorErrors[$businessId] = '';
        try {
            $business = $config->getBusiness();
            if (!$business) {
                throw new \RuntimeException('Comercio no asociado a la configuración ARCA.');
            }

            $tokenSign = $this->wsaaService->getTokenSign($business, $config, 'wsfe');
            $client = $this->createWsfeClient($config);

            $auth = [
                'Token' => $tokenSign['token'],
                'Sign' => $tokenSign['sign'],
                'Cuit' => $config->getCuitEmisor(),
            ];

            $response = $this->callWsfe($client, 'FEParamGetCondicionIvaReceptor', [
                'Auth' => $auth,
            ]);

            $raw = $response->FEParamGetCondicionIvaReceptorResult->ResultGet->CondicionIvaReceptor ?? [];
            $items = json_decode(json_encode($raw, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            if (isset($items['Id'])) {
                $items = [$items];
            }
            if (!is_array($items)) {
                $items = [];
            }

            $options = [];
            foreach ($items as $item) {
                $id = isset($item['Id']) ? (int) $item['Id'] : 0;
                $desc = isset($item['Desc']) ? (string) $item['Desc'] : '';
                if ($id > 0 && $desc !== '') {
                    $options[$id] = $desc;
                }
            }
        } catch (\Throwable $exception) {
            $fallback = $this->getFallbackCondicionIvaReceptorOptions();
            if ($fallback !== []) {
                $options = $fallback;
                $this->condicionIvaReceptorErrors[$businessId] = '';
                error_log(sprintf('[ARCA] FEParamGetCondicionIvaReceptor falló y se aplicó fallback local. business=%d env=%s error=%s',
                    $businessId,
                    $config->getArcaEnvironment(),
                    $exception->getMessage()
                ));
            } else {
                $this->condicionIvaReceptorErrors[$businessId] = $exception->getMessage();
                $options = [];
            }
        }

        $this->condicionIvaReceptorOptions[$businessId] = $options;

        return $options;
    }

    /**
     * @return array<int, string>
     */
    private function getFallbackCondicionIvaReceptorOptions(): array
    {
        return self::FALLBACK_CONDICION_IVA_RECEPTOR_OPTIONS;
    }

    public function getCondicionIvaReceptorError(BusinessArcaConfig $config): ?string
    {
        $businessId = $config->getBusiness()?->getId() ?? 0;
        $error = $this->condicionIvaReceptorErrors[$businessId] ?? null;

        return $error !== '' ? $error : null;
    }
}
