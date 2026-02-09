<?php

namespace App\Service;

class ArcaPemNormalizer
{
    public function normalizeCert(string $input): string
    {
        $trimmed = trim($input);
        if ($this->hasPemHeaders($trimmed)) {
            return $this->normalizeNewlines($trimmed);
        }

        $compact = preg_replace('/\s+/', '', $trimmed) ?? '';

        return "-----BEGIN CERTIFICATE-----\n"
            . chunk_split($compact, 64, "\n")
            . "-----END CERTIFICATE-----\n";
    }

    public function normalizeKey(string $input): string
    {
        $trimmed = trim($input);
        if ($this->hasPemHeaders($trimmed)) {
            return $this->normalizeNewlines($trimmed);
        }

        $compact = preg_replace('/\s+/', '', $trimmed) ?? '';

        return "-----BEGIN PRIVATE KEY-----\n"
            . chunk_split($compact, 64, "\n")
            . "-----END PRIVATE KEY-----\n";
    }

    public function normalizeNewlines(string $value): string
    {
        return str_replace(["\r\n", "\r"], "\n", $value);
    }

    public function validate(string $certPem, string $keyPem, ?string $passphrase): void
    {
        $x509 = openssl_x509_read($certPem);
        if ($x509 === false) {
            throw $this->buildOpenSslException('Certificado inválido (PEM).');
        }

        $pkey = openssl_pkey_get_private($keyPem, $passphrase ?? '');
        if ($pkey === false) {
            throw $this->buildOpenSslException('Clave privada inválida (PEM o passphrase).');
        }
    }

    private function hasPemHeaders(string $input): bool
    {
        return str_contains($input, '-----BEGIN') && str_contains($input, '-----END');
    }

    private function buildOpenSslException(string $message): \RuntimeException
    {
        $errors = [];
        while ($error = openssl_error_string()) {
            $errors[] = $error;
        }

        if ($errors === []) {
            return new \RuntimeException($message);
        }

        $detail = mb_substr(implode(' | ', $errors), 0, 500);

        return new \RuntimeException($message . ' Detalle: ' . $detail);
    }
}
