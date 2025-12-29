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

    public function createPreapprovalCheckout(array $payload): array
    {
        return $this->request('PUT', '/preapproval_plan/checkout', $payload);
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

    private function request(string $method, string $path, ?array $payload = null): array
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

        $this->logger->info('Mercado Pago request', [
            'correlation_id' => $correlationId,
            'method' => $method,
            'path' => $path,
            'mode' => $this->mode,
        ]);

        try {
            $response = $this->httpClient->request($method, $url, $options);
        } catch (TransportExceptionInterface $exception) {
            $this->logger->error('Mercado Pago transport error', [
                'correlation_id' => $correlationId,
                'method' => $method,
                'path' => $path,
                'mode' => $this->mode,
                'message' => $exception->getMessage(),
            ]);

            throw new MercadoPagoApiException(0, $exception->getMessage());
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

        if ($statusCode >= 400) {
            throw new MercadoPagoApiException($statusCode, $this->summarizeResponse($body));
        }

        if ($body === '') {
            return [];
        }

        $data = json_decode($body, true);

        if (!is_array($data)) {
            return ['raw' => $body];
        }

        return $data;
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
