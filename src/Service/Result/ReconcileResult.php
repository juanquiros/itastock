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
        private readonly int $stalePendingCanceled = 0,
        private readonly bool $hasInconsistency = false,
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

    public function getStalePendingCanceled(): int
    {
        return $this->stalePendingCanceled;
    }

    public function hasInconsistency(): bool
    {
        return $this->hasInconsistency;
    }
}
