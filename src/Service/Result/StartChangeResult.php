<?php

namespace App\Service\Result;

class StartChangeResult
{
    public function __construct(
        private readonly string $initPoint,
        private readonly string $mpPreapprovalId,
        private readonly string $externalReference,
    ) {
    }

    public function getInitPoint(): string
    {
        return $this->initPoint;
    }

    public function getMpPreapprovalId(): string
    {
        return $this->mpPreapprovalId;
    }

    public function getExternalReference(): string
    {
        return $this->externalReference;
    }
}
