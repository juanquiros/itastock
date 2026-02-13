<?php

namespace App\Service;

use App\Entity\ArcaCreditNote;
use App\Entity\ArcaInvoice;
use App\Entity\BusinessArcaConfig;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

class ArcaWsfeService
{
    /**
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
        private readonly ArcaSoapClientFactory $soapClientFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array{token: string, sign: string} $tokenSign
     * @return array{request: array, response: array, cbteNumero: int, cae: ?string, caeDueDate: ?DateTimeImmutable}
     */
    public function requestCae(ArcaInvoice $invoice, BusinessArcaConfig $config, array $tokenSign): array
    {
        $usedWsdl = null;
        $client = $this->createWsfeClient($config, $usedWsdl);

        $auth = [
            'Token' => $tokenSign['token'],
            'Sign' => $tokenSign['sign'],
            'Cuit' => $config->getCuitEmisor(),
        ];

        $cbteTipo = $this->resolveCbteTipoCode($invoice->getCbteTipo());
        $last = $this->wsfeCall($client, 'FECompUltimoAutorizado', [
            'Auth' => $auth,
            'PtoVta' => $invoice->getArcaPosNumber(),
            'CbteTipo' => $cbteTipo,
        ], $config, $usedWsdl);

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
                'AlicIva' => count($ivaItems) === 1 ? $ivaItems[0] : $ivaItems,
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

        $response = $this->wsfeCall($client, 'FECAESolicitar', $request, $config, $usedWsdl);
        $responseArray = json_decode(json_encode($response, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        $detailResponse = $response->FECAESolicitarResult->FeDetResp->FECAEDetResponse ?? null;
        $cae = $detailResponse->CAE ?? null;
        $caeDue = $detailResponse->CAEFchVto ?? null;

        $responseArray['_soap_last_request'] = $client->__getLastRequest();
        $responseArray['_soap_last_response'] = $client->__getLastResponse();
        $responseArray['_soap_wsdl_used'] = $usedWsdl;

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
        $usedWsdl = null;
        $client = $this->createWsfeClient($config, $usedWsdl);

        $auth = [
            'Token' => $tokenSign['token'],
            'Sign' => $tokenSign['sign'],
            'Cuit' => $config->getCuitEmisor(),
        ];

        $cbteTipo = $this->resolveCbteTipoCode($note->getCbteTipo());
        $last = $this->wsfeCall($client, 'FECompUltimoAutorizado', [
            'Auth' => $auth,
            'PtoVta' => $note->getArcaPosNumber(),
            'CbteTipo' => $cbteTipo,
        ], $config, $usedWsdl);

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
                'AlicIva' => count($ivaItems) === 1 ? $ivaItems[0] : $ivaItems,
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
                    'FECAEDetRequest' => $detail,
                ],
            ],
        ];

        $response = $this->wsfeCall($client, 'FECAESolicitar', $request, $config, $usedWsdl);
        $responseArray = json_decode(json_encode($response, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        $detailResponse = $response->FECAESolicitarResult->FeDetResp->FECAEDetResponse ?? null;
        $cae = $detailResponse->CAE ?? null;
        $caeDue = $detailResponse->CAEFchVto ?? null;

        $responseArray['_soap_last_request'] = $client->__getLastRequest();
        $responseArray['_soap_last_response'] = $client->__getLastResponse();
        $responseArray['_soap_wsdl_used'] = $usedWsdl;

        return [
            'request' => $request,
            'response' => $responseArray,
            'cbteNumero' => $cbteNumero,
            'cae' => $cae ? (string) $cae : null,
            'caeDueDate' => $caeDue ? DateTimeImmutable::createFromFormat('Ymd', (string) $caeDue) ?: null : null,
        ];
    }

    public function requestTransportCheck(BusinessArcaConfig $config, array $tokenSign, int $ptoVta = 1, int $cbteTipo = 11): void
    {
        $usedWsdl = null;
        $client = $this->createWsfeClient($config, $usedWsdl);

        $this->wsfeCall($client, 'FECompUltimoAutorizado', [
            'Auth' => [
                'Token' => $tokenSign['token'],
                'Sign' => $tokenSign['sign'],
                'Cuit' => $config->getCuitEmisor(),
            ],
            'PtoVta' => $ptoVta,
            'CbteTipo' => $cbteTipo,
        ], $config, $usedWsdl);
    }

    private function createWsfeClient(BusinessArcaConfig $config, ?string &$usedWsdl = null): \SoapClient
    {
        return $this->soapClientFactory->createWsfeClientForLocation($config, null, $usedWsdl);
    }

    /**
     * @return array<int, string>
     */
    private function getWsfeLocations(BusinessArcaConfig $config): array
    {
        return $this->soapClientFactory->getWsfeLocations($config);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function wsfeCall(\SoapClient &$client, string $method, array $payload, BusinessArcaConfig $config, ?string $usedWsdl = null): mixed
    {
        $locations = $this->getWsfeLocations($config);
        $errors = [];

        foreach ($locations as $location) {
            try {
                $client = $this->soapClientFactory->createWsfeClientForLocation($config, $location, $usedWsdl);
                $client->__setLocation($location);

                $result = $this->soapCallWrapped($client, $method, $payload);
                $this->logAuthPresenceIfNeeded($client, $method);
                $this->logSoapExchangeIfDev($client, $method, $location);

                return $result;
            } catch (\Throwable $exception) {
                $message = $exception->getMessage();
                $errors[] = sprintf('%s (wsdl=%s) => %s', $location, $usedWsdl ?? 'n/a', $message);
                $this->logger->warning('WSFE: fallo en location, se intentará siguiente si aplica.', [
                    'environment' => $config->getArcaEnvironment(),
                    'service' => $method,
                    'location' => $location,
                    'wsdl' => $usedWsdl,
                    'error' => $message,
                ]);

                if (!$this->shouldRetryOnNextLocation($message)) {
                    throw $exception;
                }
            }
        }

        $summary = sprintf(
            'WSFE call failed. service=%s env=%s wsdl=%s locations=%s',
            $method,
            $config->getArcaEnvironment(),
            $usedWsdl ?? 'n/a',
            implode(',', $locations)
        );
        $this->logger->error($summary, ['errors' => $errors]);

        throw new \RuntimeException($summary.' | '.implode(' | ', $errors));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function soapCallWrapped(\SoapClient $client, string $method, array $payload): mixed
    {
        if ($method === 'FECompUltimoAutorizado') {
            $ptoVta = $payload['PtoVta'] ?? null;
            $cbteTipo = $payload['CbteTipo'] ?? null;
            if ($ptoVta === null || $cbteTipo === null) {
                throw new \RuntimeException('WSFE FECompUltimoAutorizado requiere PtoVta y CbteTipo no nulos.');
            }

            $payload['PtoVta'] = (int) $ptoVta;
            $payload['CbteTipo'] = (int) $cbteTipo;
        }

        if ($method === 'FECAESolicitar') {
            $token = (string) ($payload['Auth']['Token'] ?? '');
            $sign = (string) ($payload['Auth']['Sign'] ?? '');
            $cuit = (string) ($payload['Auth']['Cuit'] ?? '');
            $ptoVta = $payload['FeCAEReq']['FeCabReq']['PtoVta'] ?? null;
            $cbteTipo = $payload['FeCAEReq']['FeCabReq']['CbteTipo'] ?? null;

            if ($token === '' || $sign === '' || $cuit === '') {
                throw new \RuntimeException('WSFE FECAESolicitar requiere Auth completo (Token/Sign/Cuit).');
            }
            if ($ptoVta === null || $cbteTipo === null) {
                throw new \RuntimeException('WSFE FECAESolicitar requiere FeCAEReq.FeCabReq.PtoVta y CbteTipo.');
            }

            $payload['FeCAEReq']['FeCabReq']['PtoVta'] = (int) $ptoVta;
            $payload['FeCAEReq']['FeCabReq']['CbteTipo'] = (int) $cbteTipo;
        }

        $payload = $this->normalizeSoapPayload($payload);
        $result = $client->__soapCall($method, [$payload]);

        $this->logWrongWrapperIfNeeded($client, $method);

        return $result;
    }

    private function logAuthPresenceIfNeeded(\SoapClient $client, string $method): void
    {
        if ($method !== 'FECAESolicitar') {
            return;
        }

        $request = $client->__getLastRequest() ?: '';
        if ($request !== '' && !str_contains($request, '<Auth>')) {
            $this->logger->error('Auth missing in SOAP request.', ['method' => $method]);
        }
    }

    private function shouldRetryOnNextLocation(string $message): bool
    {
        $needle = strtolower($message);

        return str_contains($needle, 'encoding:')
            || str_contains($needle, 'looks like we got no xml document')
            || str_contains($needle, 'error fetching http headers')
            || str_contains($needle, 'could not connect')
            || str_contains($needle, 'couldn\'t load')
            || str_contains($needle, 'parsing wsdl');
    }

    private function logWrongWrapperIfNeeded(\SoapClient $client, string $method): void
    {
        $request = $client->__getLastRequest() ?: '';
        if ($request === '') {
            return;
        }

        if (str_contains($request, '<param0>') && !str_contains($request, '<'.$method)) {
            $this->logger->warning('SOAP wrapper inesperado.', ['method' => $method]);
        }
    }

    private function logSoapExchangeIfDev(\SoapClient $client, string $method, string $location): void
    {
        $appEnv = strtolower((string) ($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: ''));
        if (!in_array($appEnv, ['dev', 'test'], true)) {
            return;
        }

        $request = $client->__getLastRequest() ?: '';
        $response = $client->__getLastResponse() ?: '';

        $request = preg_replace('/<(Token|Sign)>.*?<\/\1>/si', '<$1>[REDACTED]</$1>', $request) ?: $request;

        $this->logger->debug('WSFE SOAP exchange (dev/test)', [
            'method' => $method,
            'location' => $location,
            'request' => mb_substr($request, 0, 1500),
            'response' => mb_substr($response, 0, 1500),
        ]);
    }

    /**
     * @param mixed $value
     */
    private function normalizeSoapPayload(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item) => $this->normalizeSoapPayload($item), $value);
        }

        $object = new \stdClass();
        foreach ($value as $key => $item) {
            $object->{$key} = $this->normalizeSoapPayload($item);
        }

        return $object;
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
        if (array_key_exists($businessId, $this->condicionIvaReceptorOptions) && $this->condicionIvaReceptorOptions[$businessId] !== []) {
            return $this->condicionIvaReceptorOptions[$businessId];
        }

        $this->condicionIvaReceptorErrors[$businessId] = '';

        try {
            $business = $config->getBusiness();
            if (!$business) {
                throw new \RuntimeException('Comercio no asociado a la configuración ARCA.');
            }

            $tokenSign = $this->wsaaService->getTokenSign($business, $config, 'wsfe');
            $usedWsdl = null;
            $client = $this->createWsfeClient($config, $usedWsdl);

            $response = $this->wsfeCall($client, 'FEParamGetCondicionIvaReceptor', [
                'Auth' => [
                    'Token' => $tokenSign['token'],
                    'Sign' => $tokenSign['sign'],
                    'Cuit' => $config->getCuitEmisor(),
                ],
            ], $config, $usedWsdl);

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

            if ($options === []) {
                $this->logger->warning('ARCA: catálogo CondicionIvaReceptor vacío o inválido, se aplica fallback local.', [
                    'businessId' => $businessId,
                    'environment' => $config->getArcaEnvironment(),
                ]);
                $options = $this->getFallbackCondicionIvaReceptorOptions();
            }
        } catch (\Throwable $exception) {
            $options = $this->getFallbackCondicionIvaReceptorOptions();
            $this->logger->warning('ARCA: FEParamGetCondicionIvaReceptor falló, se aplicó fallback local.', [
                'businessId' => $businessId,
                'environment' => $config->getArcaEnvironment(),
                'error' => $exception->getMessage(),
            ]);
        }

        $this->condicionIvaReceptorErrors[$businessId] = '';
        if ($options !== []) {
            $this->condicionIvaReceptorOptions[$businessId] = $options;
        }

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
