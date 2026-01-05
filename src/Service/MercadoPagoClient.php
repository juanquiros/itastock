<?php

namespace App\Service;

use App\Exception\MercadoPagoApiException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class MercadoPagoClient
{
    private const BASE_URL = 'https://api.mercadopago.com';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $accessToken,
        private readonly string $mode,
        private readonly ?string $webhookSecret
    ) {
    }

    public function createPreapprovalPlan(array $payload): array
    {
        return $this->request('POST', '/preapproval_plan', $payload);
    }

    public function getPreapprovalPlan(string $planId): array
    {
        return $this->request('GET', sprintf('/preapproval_plan/%s', $planId));
    }

    public function createPreapproval(array $payload): array
    {
        return $this->request('POST', '/preapproval', $payload);
    }

    public function createPreapprovalCheckout(string $planId, array $payload): array
    {
        return $this->request('GET', sprintf('/preapproval_plan/%s/checkout', $planId), null, $payload);
    }

    public function getPreapproval(string $preapprovalId): array
    {
        return $this->request('GET', sprintf('/preapproval/%s', $preapprovalId));
    }

    public function updatePreapproval(string $preapprovalId, array $payload): array
    {
        return $this->request('PUT', sprintf('/preapproval/%s', $preapprovalId), $payload);
    }

    public function cancelPreapproval(string $preapprovalId): array
    {
        try {
            return $this->updatePreapproval($preapprovalId, ['status' => 'cancelled']);
        } catch (MercadoPagoApiException $exception) {
            if (
                $exception->getStatusCode() === 400
                && str_contains(mb_strtolower($exception->getResponseBody()), 'already cancelled')
            ) {
                $this->logger->info('Mercado Pago preapproval already cancelled.', [
                    'correlation_id' => $exception->getCorrelationId(),
                    'preapproval_id' => $preapprovalId,
                ]);

                return [];
            }

            $this->logger->warning('Mercado Pago cancel preapproval failed.', [
                'correlation_id' => $exception->getCorrelationId(),
                'preapproval_id' => $preapprovalId,
                'status_code' => $exception->getStatusCode(),
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    public function getPayment(string $paymentId): array
    {
        return $this->request('GET', sprintf('/v1/payments/%s', $paymentId));
    }

    /**
     * @return array<int, array{id: string, status: string|null, date_created: string|null, last_modified: string|null, reason: string|null, payer_email: string|null}>
     */
    public function searchPreapprovalsByExternalReference(string $externalReference): array
    {
        $response = $this->request('GET', '/preapproval/search', null, [
            'external_reference' => $externalReference,
        ]);

        $results = $response['results'] ?? $response;
        if (!is_array($results)) {
            return [];
        }

        $normalized = [];
        foreach ($results as $preapproval) {
            if (!is_array($preapproval)) {
                continue;
            }

            $id = $preapproval['id'] ?? null;
            if (!is_string($id) || $id === '') {
                continue;
            }

            $normalized[] = [
                'id' => $id,
                'status' => is_string($preapproval['status'] ?? null) ? $preapproval['status'] : null,
                'date_created' => is_string($preapproval['date_created'] ?? null) ? $preapproval['date_created'] : null,
                'last_modified' => is_string($preapproval['last_modified'] ?? null) ? $preapproval['last_modified'] : null,
                'reason' => is_string($preapproval['reason'] ?? null) ? $preapproval['reason'] : null,
                'payer_email' => is_string($preapproval['payer_email'] ?? null) ? $preapproval['payer_email'] : null,
            ];
        }

        return $normalized;
    }

    private function request(string $method, string $path, ?array $payload = null, ?array $query = null): array
    {
        $correlationId = bin2hex(random_bytes(16));
        $url = sprintf('%s%s', self::BASE_URL, $path);
        $idempotencyKey = $this->resolveIdempotencyKey($method, $path);

        $options = [
            'headers' => [
                'Authorization' => sprintf('Bearer %s', $this->accessToken),
                'X-Correlation-Id' => $correlationId,
            ],
        ];
        if ($idempotencyKey !== null) {
            $options['headers']['X-Idempotency-Key'] = $idempotencyKey;
        }

        if ($payload !== null) {
            $options['json'] = $payload;
        }

        if ($query !== null) {
            $options['query'] = $query;
        }

        $attempt = 0;
        $transportRetries = 0;
        $maxTransportRetries = 2;
        $maxRateLimitRetries = 6;
        $maxServerRetries = 2;

        while (true) {
            $attempt++;
            $this->logger->info('Mercado Pago request', [
                'correlation_id' => $correlationId,
                'idempotency_key' => $idempotencyKey,
                'method' => $method,
                'path' => $path,
                'mode' => $this->mode,
                'attempt' => $attempt,
            ]);

            try {
                $response = $this->httpClient->request($method, $url, $options);
            } catch (TransportExceptionInterface $exception) {
                $transportRetries++;
                if ($transportRetries <= $maxTransportRetries) {
                    $this->logger->warning('Mercado Pago transport error, retrying.', [
                        'correlation_id' => $correlationId,
                        'method' => $method,
                        'path' => $path,
                        'mode' => $this->mode,
                        'message' => $exception->getMessage(),
                        'attempt' => $attempt,
                    ]);
                    $this->sleepWithBackoff($transportRetries, 1);
                    continue;
                }

                $this->logger->error('Mercado Pago transport error', [
                    'correlation_id' => $correlationId,
                    'method' => $method,
                    'path' => $path,
                    'mode' => $this->mode,
                    'message' => $exception->getMessage(),
                ]);

                throw new MercadoPagoApiException(0, $exception->getMessage(), $correlationId);
            }

            $statusCode = $response->getStatusCode();
            $body = $response->getContent(false);

            $this->logger->info('Mercado Pago response', [
                'correlation_id' => $correlationId,
                'idempotency_key' => $idempotencyKey,
                'status_code' => $statusCode,
                'method' => $method,
                'path' => $path,
                'mode' => $this->mode,
            ]);

            if ($statusCode === 429 && $this->isLocalRateLimited($body)) {
                if ($attempt < $maxRateLimitRetries) {
                    $this->logger->warning('Mercado Pago rate limited, retrying.', [
                        'correlation_id' => $correlationId,
                        'method' => $method,
                        'path' => $path,
                        'mode' => $this->mode,
                        'attempt' => $attempt,
                    ]);
                    $retryAfter = $this->parseRetryAfter($response->getHeaders(false));
                    $this->sleepWithBackoff($attempt, $retryAfter);
                    continue;
                }
            }

            if ($statusCode >= 500 && $statusCode < 600 && $attempt <= $maxServerRetries) {
                $this->logger->warning('Mercado Pago server error, retrying.', [
                    'correlation_id' => $correlationId,
                    'method' => $method,
                    'path' => $path,
                    'mode' => $this->mode,
                    'attempt' => $attempt,
                    'status_code' => $statusCode,
                ]);
                $this->sleepWithBackoff($attempt, 1);
                continue;
            }

            if ($statusCode >= 400) {
                throw new MercadoPagoApiException($statusCode, $this->summarizeResponse($body), $correlationId);
            }

            break;
        }

        if ($body === '') {
            return [];
        }

        $data = json_decode($body, true);

        if (is_array($data)) {
            if (isset($data['response_content']) && is_string($data['response_content'])) {
                $nested = json_decode($data['response_content'], true);
                if (is_array($nested)) {
                    return $nested;
                }
            }

            return $data;
        }

        $trimmedBody = trim($body);
        if ($trimmedBody !== '' && $trimmedBody !== $body) {
            $data = json_decode($trimmedBody, true);
            if (is_array($data)) {
                return $data;
            }
        }

        return ['raw' => $body];
    }

    private function summarizeResponse(string $body): string
    {
        $summary = trim($body);

        if ($summary === '') {
            return 'empty response';
        }

        $maxLength = 500;

        if (mb_strlen($summary) > $maxLength) {
            return sprintf('%s...', mb_substr($summary, 0, $maxLength));
        }

        return $summary;
    }

    private function isLocalRateLimited(string $body): bool
    {
        return str_contains(mb_strtolower($body), 'local_rate_limited');
    }

    /**
     * @param array<string, string[]> $headers
     */
    private function parseRetryAfter(array $headers): ?int
    {
        $retryAfter = $headers['retry-after'][0] ?? null;
        if ($retryAfter === null) {
            return null;
        }

        if (ctype_digit((string) $retryAfter)) {
            return (int) $retryAfter;
        }

        $timestamp = strtotime($retryAfter);
        if ($timestamp !== false) {
            $delta = $timestamp - time();

            return $delta > 0 ? $delta : null;
        }

        return null;
    }

    private function sleepWithBackoff(int $attempt, ?int $baseSeconds): void
    {
        $backoff = [2, 4, 8, 16, 32, 60];
        $index = max(0, min($attempt - 1, count($backoff) - 1));
        $seconds = $backoff[$index];
        if ($baseSeconds !== null) {
            $seconds = max($seconds, $baseSeconds);
        }

        $jitterMs = random_int(0, 500);
        $sleepMs = ($seconds * 1000) + $jitterMs;
        usleep($sleepMs * 1000);
    }

    private function resolveIdempotencyKey(string $method, string $path): ?string
    {
        $normalizedMethod = strtoupper($method);
        if (!in_array($normalizedMethod, ['POST', 'PUT'], true)) {
            return null;
        }

        if ($path !== '/preapproval' && !str_starts_with($path, '/preapproval/')) {
            return null;
        }

        if ($path === '/preapproval' && $normalizedMethod === 'POST') {
            return bin2hex(random_bytes(16));
        }

        return substr(hash('sha256', $normalizedMethod.$path), 0, 32);
    }
}
