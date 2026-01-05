<?php

namespace App\Exception;

class MercadoPagoApiException extends \RuntimeException
{
    public function __construct(
        private readonly int $statusCode,
        private readonly string $responseBody,
        private readonly ?string $correlationId = null,
    ) {
        parent::__construct(sprintf(
            'Mercado Pago API request failed with status %d: %s',
            $statusCode,
            $responseBody
        ));
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getResponseBody(): string
    {
        return $this->responseBody;
    }

    public function getCorrelationId(): ?string
    {
        return $this->correlationId;
    }
}
