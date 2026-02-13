<?php

namespace App\Service;

use App\Entity\BusinessArcaConfig;
use Psr\Log\LoggerInterface;

class ArcaSoapClientFactory
{
    private const DEFAULT_CA_BUNDLE_CANDIDATES = [
        '/etc/pki/ca-trust/extracted/pem/tls-ca-bundle.pem',
        '/etc/ssl/certs/ca-bundle.crt',
        '/etc/ssl/certs/ca-certificates.crt',
    ];

    /**
     * @param array<int, string> $wsaaHomoWsdls
     * @param array<int, string> $wsaaProdWsdls
     * @param array<int, string> $wsfeHomoWsdls
     * @param array<int, string> $wsfeProdWsdls
     * @param array<int, string> $wsfeHomoLocations
     * @param array<int, string> $wsfeProdLocations
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly array $wsaaHomoWsdls,
        private readonly array $wsaaProdWsdls,
        private readonly array $wsfeHomoWsdls,
        private readonly array $wsfeProdWsdls,
        private readonly array $wsfeHomoLocations,
        private readonly array $wsfeProdLocations,
        private readonly ?string $arcaCaBundle,
        private readonly ?string $wsaaHomoWsdlOverride,
        private readonly ?string $wsaaProdWsdlOverride,
        private readonly ?string $wsfeHomoWsdlOverride,
        private readonly ?string $wsfeProdWsdlOverride,
        private readonly int $httpTimeout = 30,
        private readonly int $connectionTimeout = 30,
        private readonly string $userAgent = 'ItaStock-ARCA-SOAP/1.0',
        private readonly bool $trace = true,
        private readonly ?string $wsfeProdSslCiphers = null,
        private readonly bool $wsfeProdLowerSecLevelEnabled = false,
        private readonly bool $wsfeHomoLowerSecLevelEnabled = false,
        private readonly bool $wsfeProdDisableSecondaryHost = true,
    ) {
    }

    public function createWsaaClient(BusinessArcaConfig $config): \SoapClient
    {
        $wsdls = $this->getWsaaWsdls($config);

        return $this->createClientWithFallback(
            $wsdls,
            $this->buildSoapOptionsForContext(false, false),
            'WSAA',
            $config
        );
    }

    public function createWsfeClient(BusinessArcaConfig $config): \SoapClient
    {
        $locations = $this->getWsfeLocations($config);

        return $this->createWsfeClientForLocation($config, $locations[0] ?? null);
    }

    public function createWsfeClientForLocation(BusinessArcaConfig $config, ?string $location, ?string &$usedWsdl = null): \SoapClient
    {
        $isProd = $config->getArcaEnvironment() === BusinessArcaConfig::ENV_PROD;
        $isWsfeProd = $isProd;
        $wsdls = $this->getWsfeWsdls($config);
        $options = $this->buildSoapOptionsForContext(true, $isWsfeProd) + [
            'soap_version' => SOAP_1_2,
            'encoding' => 'UTF-8',
            'cache_wsdl' => WSDL_CACHE_NONE,
        ];

        if ($location) {
            $options['location'] = $location;
        }

        if ($wsdls === []) {
            throw new \RuntimeException('WSFE: no hay WSDLs configurados.');
        }

        $errors = [];
        foreach ($wsdls as $wsdl) {
            try {
                $this->logger->debug('ARCA SOAP: intentando WSDL', [
                    'service' => 'WSFE',
                    'environment' => $config->getArcaEnvironment(),
                    'seclevel_downgrade' => $this->isWsfeSecLevelDowngradeEnabled($isWsfeProd) ? 'ON' : 'OFF',
                    'wsdl' => $wsdl,
                ]);
                $usedWsdl = $wsdl;
                $this->logger->info('wsfe env={env} seclevel_downgrade={downgrade} wsdl={wsdl}', [
                    'env' => $config->getArcaEnvironment(),
                    'downgrade' => $this->isWsfeSecLevelDowngradeEnabled($isWsfeProd) ? 'ON' : 'OFF',
                    'wsdl' => $wsdl,
                ]);

                return new \SoapClient($wsdl, $options);
            } catch (\Throwable $exception) {
                $errors[] = sprintf('%s => %s', $wsdl, $exception->getMessage());
                $this->logger->warning('ARCA SOAP: fallo al cargar WSDL, se intentará siguiente.', [
                    'service' => 'WSFE',
                    'environment' => $config->getArcaEnvironment(),
                    'wsdl' => $wsdl,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $message = 'WSFE: no se pudo inicializar SoapClient con ningún WSDL.';
        $this->logger->error($message, [
            'environment' => $config->getArcaEnvironment(),
            'errors' => $errors,
        ]);

        throw new \RuntimeException($message.' '.implode(' | ', $errors));
    }

    /**
     * @return array<int, string>
     */
    public function getWsfeLocations(BusinessArcaConfig $config): array
    {
        $locations = $config->getArcaEnvironment() === BusinessArcaConfig::ENV_PROD
            ? $this->wsfeProdLocations
            : $this->wsfeHomoLocations;

        if ($config->getArcaEnvironment() === BusinessArcaConfig::ENV_PROD && $this->wsfeProdDisableSecondaryHost) {
            $locations = $this->withoutSecondaryWsfeHost($locations);
        }

        return array_values(array_filter(array_map('trim', $locations), static fn (string $url) => $url !== ''));
    }

    /**
     * @return array<int, string>
     */
    public function detectCaBundles(): array
    {
        $candidates = self::DEFAULT_CA_BUNDLE_CANDIDATES;
        $detected = [];
        foreach ($candidates as $candidate) {
            if (is_readable($candidate)) {
                $detected[] = $candidate;
            }
        }

        return $detected;
    }

    /**
     * @param array<int, string> $defaults
     * @return array<int, string>
     */
    public function buildWsdlCandidates(array $defaults, ?string $override): array
    {
        $result = array_values(array_filter(array_map('trim', $defaults), static fn (string $url) => $url !== ''));
        $override = trim((string) $override);
        if ($override !== '') {
            $result = array_values(array_unique(array_merge([$override], $result)));
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSoapOptionsForContext(bool $isWsfe, bool $isWsfeProd): array
    {
        return [
            'trace' => $this->trace,
            'exceptions' => true,
            'connection_timeout' => $this->connectionTimeout,
            'stream_context' => $this->buildStreamContext($isWsfe && $isWsfeProd),
        ];
    }

    /**
     * @return resource
     */
    private function buildStreamContext(bool $isWsfeProd)
    {
        $ssl = [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'SNI_enabled' => true,
            'allow_self_signed' => false,
        ];

        $caBundle = $this->resolveCaBundle();
        if ($caBundle !== null) {
            $ssl['cafile'] = $caBundle;
        }

        if ($this->isWsfeSecLevelDowngradeEnabled($isWsfeProd)) {
            $ssl['ciphers'] = trim((string) $this->wsfeProdSslCiphers) !== ''
                ? trim((string) $this->wsfeProdSslCiphers)
                : 'DEFAULT@SECLEVEL=1';
        }

        return stream_context_create([
            'ssl' => $ssl,
            'http' => [
                'timeout' => $this->httpTimeout,
                'user_agent' => $this->userAgent,
            ],
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function getWsaaWsdls(BusinessArcaConfig $config): array
    {
        return $config->getArcaEnvironment() === BusinessArcaConfig::ENV_PROD
            ? $this->buildWsdlCandidates($this->wsaaProdWsdls, $this->wsaaProdWsdlOverride)
            : $this->buildWsdlCandidates($this->wsaaHomoWsdls, $this->wsaaHomoWsdlOverride);
    }

    /**
     * @return array<int, string>
     */
    private function getWsfeWsdls(BusinessArcaConfig $config): array
    {
        $wsdls = $config->getArcaEnvironment() === BusinessArcaConfig::ENV_PROD
            ? $this->buildWsdlCandidates($this->wsfeProdWsdls, $this->wsfeProdWsdlOverride)
            : $this->buildWsdlCandidates($this->wsfeHomoWsdls, $this->wsfeHomoWsdlOverride);

        if ($config->getArcaEnvironment() === BusinessArcaConfig::ENV_PROD && $this->wsfeProdDisableSecondaryHost) {
            return $this->withoutSecondaryWsfeHost($wsdls);
        }

        return $wsdls;
    }

    private function resolveCaBundle(): ?string
    {
        $caBundle = trim((string) $this->arcaCaBundle);
        if ($caBundle !== '' && is_readable($caBundle)) {
            return $caBundle;
        }

        if ($caBundle !== '') {
            $this->logger->warning('ARCA: CA bundle configurado pero no legible.', ['cafile' => $caBundle]);

            return null;
        }

        $detected = $this->detectCaBundles();

        return $detected[0] ?? null;
    }

    private function isWsfeSecLevelDowngradeEnabled(bool $isWsfeProd): bool
    {
        if ($isWsfeProd) {
            return $this->wsfeProdLowerSecLevelEnabled;
        }

        return $this->wsfeHomoLowerSecLevelEnabled;
    }

    /**
     * @param array<int, string> $urls
     * @return array<int, string>
     */
    private function withoutSecondaryWsfeHost(array $urls): array
    {
        return array_values(array_filter($urls, static function (string $url): bool {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));

            return $host !== 'wsfev1.afip.gov.ar';
        }));
    }

    /**
     * @param array<int, string> $wsdls
     * @param array<string, mixed> $options
     */
    private function createClientWithFallback(array $wsdls, array $options, string $service, BusinessArcaConfig $config): \SoapClient
    {
        if ($wsdls === []) {
            throw new \RuntimeException(sprintf('%s: no hay WSDLs configurados.', $service));
        }

        $errors = [];
        foreach ($wsdls as $wsdl) {
            try {
                $this->logger->debug('ARCA SOAP: intentando WSDL', [
                    'service' => $service,
                    'environment' => $config->getArcaEnvironment(),
                    'wsdl' => $wsdl,
                ]);

                return new \SoapClient($wsdl, $options);
            } catch (\Throwable $exception) {
                $errors[] = sprintf('%s => %s', $wsdl, $exception->getMessage());
                $this->logger->warning('ARCA SOAP: fallo al cargar WSDL, se intentará siguiente.', [
                    'service' => $service,
                    'environment' => $config->getArcaEnvironment(),
                    'wsdl' => $wsdl,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $message = sprintf('%s: no se pudo inicializar SoapClient con ningún WSDL.', $service);
        $this->logger->error($message, [
            'service' => $service,
            'environment' => $config->getArcaEnvironment(),
            'errors' => $errors,
        ]);

        throw new \RuntimeException($message.' '.implode(' | ', $errors));
    }

}
