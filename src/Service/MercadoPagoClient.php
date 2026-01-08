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
        return $this->updatePreapproval($preapprovalId, ['status' => 'cancelled']);
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

        $options = [
            'headers' => [
                'Authorization' => sprintf('Bearer %s', $this->accessToken),
                'X-Correlation-Id' => $correlationId,
            ],
        ];

        if ($payload !== null) {
            $options['json'] = $payload;
        }

        if ($query !== null) {
            $options['query'] = $query;
        }

        $idempotent = in_array(strtoupper($method), ['POST', 'PUT'], true)
            && str_starts_with($path, '/preapproval');
        if ($idempotent) {
            $options['headers']['X-Idempotency-Key'] = $this->resolveIdempotencyKey($method, $path, $payload);
        }

        $maxAttempts = 3;
        $maxRateLimitRetries = 3;
        $attempt = 0;
        $rateLimitRetries = 0;

        while (true) {
            $attempt++;

            $this->logger->info('Mercado Pago request', [
                'correlation_id' => $correlationId,
                'method' => $method,
                'path' => $path,
                'mode' => $this->mode,
                'attempt' => $attempt,
            ]);

            try {
                $response = $this->httpClient->request($method, $url, $options);
            } catch (TransportExceptionInterface $exception) {
                if ($attempt < $maxAttempts) {
                    $this->logger->warning('Mercado Pago transport error, retrying.', [
                        'correlation_id' => $correlationId,
                        'method' => $method,
                        'path' => $path,
                        'mode' => $this->mode,
                        'attempt' => $attempt,
                        'exception' => $exception->getMessage(),
                    ]);
                    $this->sleepWithBackoff($attempt, null);

                    continue;
                }

                $this->logger->error('Mercado Pago transport error, giving up.', [
                    'correlation_id' => $correlationId,
                    'method' => $method,
                    'path' => $path,
                    'mode' => $this->mode,
                    'exception' => $exception->getMessage(),
                ]);

                throw new MercadoPagoApiException(0, $exception->getMessage(), $exception);
            }

            $statusCode = $response->getStatusCode();
            $body = $response->getContent(false);

            $this->logger->info('Mercado Pago response', [
                'correlation_id' => $correlationId,
                'status_code' => $statusCode,
                'method' => $method,
                'path' => $path,
                'mode' => $this->mode,
            ]);

            $isRateLimited = $statusCode === 429
                || stripos($body, 'local_rate_limited') !== false
                || stripos($body, 'rate limit') !== false;

            if ($isRateLimited) {
                $rateLimitRetries++;
                if ($rateLimitRetries <= $maxRateLimitRetries) {
                    $this->logger->warning('Mercado Pago rate limited, retrying.', [
                        'correlation_id' => $correlationId,
                        'method' => $method,
                        'path' => $path,
                        'mode' => $this->mode,
                        'status_code' => $statusCode,
                        'attempt' => $attempt,
                        'rate_limit_retry' => $rateLimitRetries,
                    ]);

                    $retryAfter = $this->parseRetryAfter($response->getHeaders(false));
                    $this->sleepWithBackoff($attempt, $retryAfter);

                    continue;
                }

                $this->logger->error('Mercado Pago rate limit exceeded, giving up.', [
                    'correlation_id' => $correlationId,
                    'method' => $method,
                    'path' => $path,
                    'mode' => $this->mode,
                    'status_code' => $statusCode,
                ]);

                throw new MercadoPagoApiException($statusCode, $this->summarizeResponse($body));
            }

            if ($statusCode >= 500 && $statusCode <= 599 && $attempt < $maxAttempts) {
                $this->logger->warning('Mercado Pago server error, retrying.', [
                    'correlation_id' => $correlationId,
                    'method' => $method,
                    'path' => $path,
                    'mode' => $this->mode,
                    'status_code' => $statusCode,
                    'attempt' => $attempt,
                ]);

                $this->sleepWithBackoff($attempt, null);
                continue;
            }

            if ($statusCode >= 400) {
                throw new MercadoPagoApiException($statusCode, $this->summarizeResponse($body));
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

            return [];
        }
    }

    private function resolveIdempotencyKey(string $method, string $path, ?array $payload): string
    {
        $method = strtoupper($method);

        if ($method === 'POST' && $path === '/preapproval') {
            return bin2hex(random_bytes(16));
        }

        if ($method === 'PUT' && str_starts_with($path, '/preapproval/')) {
            return hash('sha256', sprintf('%s:%s', $method, $path));
        }

        return hash('sha256', sprintf('%s:%s:%s', $method, $path, json_encode($payload)));
    }

    private function parseRetryAfter(array $headers): ?int
    {
        $retryAfter = null;
        $lower = [];
        foreach ($headers as $name => $values) {
            $lower[strtolower($name)] = $values;
        }

        if (isset($lower['retry-after'][0])) {
            $candidate = $lower['retry-after'][0];
            if (is_numeric($candidate)) {
                $retryAfter = (int) $candidate;
            }
        }

        return $retryAfter !== null && $retryAfter > 0 ? $retryAfter : null;
    }

    private function sleepWithBackoff(int $attempt, ?int $retryAfterSeconds): void
    {
        if ($retryAfterSeconds !== null && $retryAfterSeconds > 0 && $retryAfterSeconds <= 60) {
            sleep($retryAfterSeconds);
            return;
        }

        $delaySeconds = min(0.5 * (2 ** max(0, $attempt - 1)), 8);
        usleep((int) ($delaySeconds * 1_000_000));
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
}
