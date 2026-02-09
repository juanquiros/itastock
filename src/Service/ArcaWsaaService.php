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
        private readonly ArcaPemNormalizer $pemNormalizer,
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

        $cert = str_replace(["\\n", "\r\n", "\r"], ["\n", "\n", "\n"], trim($cert));
        $key = str_replace(["\\n", "\r\n", "\r"], ["\n", "\n", "\n"], trim($key));
        $passphrase = $config->getPassphrase() ?? '';

        $x509 = openssl_x509_read($cert);
        if ($x509 === false) {
            throw new \RuntimeException('Certificado PEM inválido (OpenSSL no puede leerlo).');
        }

        $pkey = openssl_pkey_get_private($key, $passphrase);
        if ($pkey === false) {
            throw new \RuntimeException('Clave privada PEM inválida o passphrase incorrecta.');
        }

        $input = tempnam(sys_get_temp_dir(), 'arca_tra_');
        $output = tempnam(sys_get_temp_dir(), 'arca_cms_');

        if (!$input || !$output) {
            throw new \RuntimeException('No se pudieron crear archivos temporales para firmar.');
        }

        file_put_contents($input, $tra);

        $privArg = $passphrase !== '' ? [$pkey, $passphrase] : $pkey;
        $signed = openssl_pkcs7_sign(
            $input,
            $output,
            $x509,
            $privArg,
            [],
            PKCS7_BINARY | PKCS7_NOATTR
        );

        if ($signed !== true) {
            $errors = [];
            while ($error = openssl_error_string()) {
                $errors[] = $error;
            }
            $detail = implode(' | ', array_slice($errors, 0, 5));
            throw new \RuntimeException('No se pudo firmar el TRA con OpenSSL. ' . $detail);
        }

        $smime = file_get_contents($output) ?: '';
        $smime = str_replace(["\r\n", "\r"], "\n", $smime);
        $parts = preg_split("/\n\n/", $smime, 2);
        if (!$parts || count($parts) < 2) {
            throw new \RuntimeException('SMIME inesperado: no se pudo extraer el bloque base64.');
        }
        $b64 = preg_replace('/-----BEGIN [^-]+-----|-----END [^-]+-----/', '', $parts[1]) ?? '';
        $b64Clean = preg_replace('/\s+/', '', trim($b64)) ?? '';
        $der = base64_decode($b64Clean, true);
        if ($der === false) {
            error_log(sprintf(
                'ARCA CMS base64 inválido. len_raw=%d len_clean=%d head=%s',
                strlen($b64),
                strlen($b64Clean),
                substr($b64Clean, 0, 40)
            ));
            throw new \RuntimeException('CMS base64 inválido (post-clean).');
        }
        $cms = base64_encode($der);

        @unlink($input);
        @unlink($output);

        if ($cms === '') {
            throw new \RuntimeException('No se pudo generar el CMS para WSAA.');
        }

        return $cms;
    }
}
