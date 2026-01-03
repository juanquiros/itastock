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
        private readonly int $activeBefore = 0,
        private readonly int $activeAfter = 0,
        private readonly ?string $keptPreapprovalId = null,
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

    public function getActiveBefore(): int
    {
        return $this->activeBefore;
    }

    public function getActiveAfter(): int
    {
        return $this->activeAfter;
    }

    public function getKeptPreapprovalId(): ?string
    {
        return $this->keptPreapprovalId;
    }
}
