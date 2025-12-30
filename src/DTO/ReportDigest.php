<?php

namespace App\DTO;

class ReportDigest
{
    public function __construct(
        private string $businessName,
        private \DateTimeImmutable $periodStart,
        private \DateTimeImmutable $periodEnd,
    ) {
    }

    private ?int $salesCount = null;
    private ?float $salesTotal = null;
    private ?int $cashOpenCount = null;
    private ?int $cashCloseCount = null;
    private ?float $cashDifferenceTotal = null;
    private ?float $movementsInTotal = null;
    private ?float $movementsOutTotal = null;
    /** @var array<int, array{name: string, qty: float, total: float}>|null */
    private ?array $topProducts = null;
    private ?int $debtorsCount = null;
    private ?float $debtorsTotal = null;
    /** @var array<int, array{name: string, stock: float, minStock: float}>|null */
    private ?array $lowStock = null;
    /** @var string[] */
    private array $notes = [];

    public function getBusinessName(): string
    {
        return $this->businessName;
    }

    public function getPeriodStart(): \DateTimeImmutable
    {
        return $this->periodStart;
    }

    public function getPeriodEnd(): \DateTimeImmutable
    {
        return $this->periodEnd;
    }

    public function getSalesCount(): ?int
    {
        return $this->salesCount;
    }

    public function setSalesCount(?int $salesCount): self
    {
        $this->salesCount = $salesCount;

        return $this;
    }

    public function getSalesTotal(): ?float
    {
        return $this->salesTotal;
    }

    public function setSalesTotal(?float $salesTotal): self
    {
        $this->salesTotal = $salesTotal;

        return $this;
    }

    public function getCashOpenCount(): ?int
    {
        return $this->cashOpenCount;
    }

    public function setCashOpenCount(?int $cashOpenCount): self
    {
        $this->cashOpenCount = $cashOpenCount;

        return $this;
    }

    public function getCashCloseCount(): ?int
    {
        return $this->cashCloseCount;
    }

    public function setCashCloseCount(?int $cashCloseCount): self
    {
        $this->cashCloseCount = $cashCloseCount;

        return $this;
    }

    public function getCashDifferenceTotal(): ?float
    {
        return $this->cashDifferenceTotal;
    }

    public function setCashDifferenceTotal(?float $cashDifferenceTotal): self
    {
        $this->cashDifferenceTotal = $cashDifferenceTotal;

        return $this;
    }

    public function getMovementsInTotal(): ?float
    {
        return $this->movementsInTotal;
    }

    public function setMovementsInTotal(?float $movementsInTotal): self
    {
        $this->movementsInTotal = $movementsInTotal;

        return $this;
    }

    public function getMovementsOutTotal(): ?float
    {
        return $this->movementsOutTotal;
    }

    public function setMovementsOutTotal(?float $movementsOutTotal): self
    {
        $this->movementsOutTotal = $movementsOutTotal;

        return $this;
    }

    /**
     * @return array<int, array{name: string, qty: float, total: float}>|null
     */
    public function getTopProducts(): ?array
    {
        return $this->topProducts;
    }

    /**
     * @param array<int, array{name: string, qty: float, total: float}>|null $topProducts
     */
    public function setTopProducts(?array $topProducts): self
    {
        $this->topProducts = $topProducts;

        return $this;
    }

    public function getDebtorsCount(): ?int
    {
        return $this->debtorsCount;
    }

    public function setDebtorsCount(?int $debtorsCount): self
    {
        $this->debtorsCount = $debtorsCount;

        return $this;
    }

    public function getDebtorsTotal(): ?float
    {
        return $this->debtorsTotal;
    }

    public function setDebtorsTotal(?float $debtorsTotal): self
    {
        $this->debtorsTotal = $debtorsTotal;

        return $this;
    }

    /**
     * @return array<int, array{name: string, stock: float, minStock: float}>|null
     */
    public function getLowStock(): ?array
    {
        return $this->lowStock;
    }

    /**
     * @param array<int, array{name: string, stock: float, minStock: float}>|null $lowStock
     */
    public function setLowStock(?array $lowStock): self
    {
        $this->lowStock = $lowStock;

        return $this;
    }

    /**
     * @return string[]
     */
    public function getNotes(): array
    {
        return $this->notes;
    }

    public function addNote(string $note): self
    {
        $this->notes[] = $note;

        return $this;
    }
}
