<?php

namespace App\Service\Result;

class ReconcileResult
{
    /**
     * @param string[] $updatedLinks
     * @param string[] $canceledPreapprovals
     */
    public function __construct(
        private readonly array $updatedLinks = [],
        private readonly array $canceledPreapprovals = [],
    ) {
    }

    /**
     * @return string[]
     */
    public function getUpdatedLinks(): array
    {
        return $this->updatedLinks;
    }

    /**
     * @return string[]
     */
    public function getCanceledPreapprovals(): array
    {
        return $this->canceledPreapprovals;
    }
}
