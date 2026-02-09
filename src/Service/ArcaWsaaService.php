<?php

namespace App\Service;

use App\Entity\Business;
use App\Entity\BusinessArcaConfig;
use App\Entity\ArcaTokenCache;
use App\Repository\ArcaTokenCacheRepository;
use DateInterval;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

class ArcaWsaaService
{
    public function __construct(
        private readonly ArcaTokenCacheRepository $tokenCacheRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly string $arcaWsaaWsdlHomo,
        private readonly string $arcaWsaaWsdlProd,
    ) {
    }

    /**
     * @return array{token: string, sign: string, expiresAt: DateTimeImmutable}
     */
    public function getTokenSign(Business $business, BusinessArcaConfig $config, string $service): array
    {
        if (!$config->isArcaEnabled()) {
            throw new \RuntimeException('ARCA no está habilitado para este comercio.');
        }

        $environment = $config->getArcaEnvironment();
        $existing = $this->tokenCacheRepository->findOneBy([
            'business' => $business,
            'service' => $service,
            'environment' => $environment,
        ]);

        $now = new DateTimeImmutable();
        if ($existing && $existing->getExpiresAt() > $now->add(new DateInterval('PT5M'))) {
            return [
                'token' => $existing->getToken(),
                'sign' => $existing->getSign(),
                'expiresAt' => $existing->getExpiresAt(),
            ];
        }

        $wsdl = $environment === BusinessArcaConfig::ENV_PROD ? $this->arcaWsaaWsdlProd : $this->arcaWsaaWsdlHomo;
        if ($wsdl === '') {
            throw new \RuntimeException('WSDL de WSAA no configurado.');
        }

        $tra = $this->buildTra($service);
        $cms = $this->signTra($tra, $config);

        $client = new \SoapClient($wsdl, [
            'trace' => 1,
            'exceptions' => true,
        ]);

        $response = $client->loginCms(['in0' => $cms]);
        $responseXml = new \SimpleXMLElement($response->loginCmsReturn ?? '');
        $token = (string) $responseXml->credentials->token;
        $sign = (string) $responseXml->credentials->sign;
        $expirationTime = (string) $responseXml->header->expirationTime;

        if ($token === '' || $sign === '') {
            throw new \RuntimeException('No se pudo obtener Token/Sign desde WSAA.');
        }

        $expiresAt = new DateTimeImmutable($expirationTime ?: 'now');

        $cache = $existing ?? new ArcaTokenCache();
        $cache->setBusiness($business);
        $cache->setService($service);
        $cache->setEnvironment($environment);
        $cache->setToken($token);
        $cache->setSign($sign);
        $cache->setExpiresAt($expiresAt);

        $this->entityManager->persist($cache);
        $this->entityManager->flush();

        return [
            'token' => $token,
            'sign' => $sign,
            'expiresAt' => $expiresAt,
        ];
    }

    private function buildTra(string $service): string
    {
        $now = new DateTimeImmutable();
        $uniqueId = (int) $now->format('U');
        $generationTime = $now->sub(new DateInterval('PT5M'))->format('c');
        $expirationTime = $now->add(new DateInterval('PT12H'))->format('c');

        return sprintf(
            '<?xml version="1.0" encoding="UTF-8"?>' .
            '<loginTicketRequest version="1.0">' .
            '<header><uniqueId>%d</uniqueId><generationTime>%s</generationTime><expirationTime>%s</expirationTime></header>' .
            '<service>%s</service></loginTicketRequest>',
            $uniqueId,
            $generationTime,
            $expirationTime,
            $service
        );
    }

    private function signTra(string $tra, BusinessArcaConfig $config): string
    {
        $cert = $config->getCertPem();
        $key = $config->getPrivateKeyPem();
        if (!$cert || !$key) {
            throw new \RuntimeException('Certificado y key privada son requeridos para firmar el TRA.');
        }

        $input = tempnam(sys_get_temp_dir(), 'arca_tra_');
        $output = tempnam(sys_get_temp_dir(), 'arca_cms_');
        $certFile = tempnam(sys_get_temp_dir(), 'arca_cert_');
        $keyFile = tempnam(sys_get_temp_dir(), 'arca_key_');

        if (!$input || !$output || !$certFile || !$keyFile) {
            throw new \RuntimeException('No se pudieron crear archivos temporales para firmar.');
        }

        file_put_contents($input, $tra);
        file_put_contents($certFile, $cert);
        file_put_contents($keyFile, $key);

        $passphrase = $config->getPassphrase() ?? '';
        $signed = openssl_pkcs7_sign(
            $input,
            $output,
            'file://' . $certFile,
            ['file://' . $keyFile, $passphrase],
            [],
            PKCS7_BINARY | PKCS7_NOATTR
        );

        if ($signed !== true) {
            throw new \RuntimeException('No se pudo firmar el TRA con OpenSSL.');
        }

        $cms = file_get_contents($output) ?: '';
        $cms = preg_replace('/-----BEGIN PKCS7-----|-----END PKCS7-----|\s/', '', $cms) ?: '';

        @unlink($input);
        @unlink($output);
        @unlink($certFile);
        @unlink($keyFile);

        if ($cms === '') {
            throw new \RuntimeException('No se pudo generar el CMS para WSAA.');
        }

        return $cms;
    }
}
