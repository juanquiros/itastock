<?php

namespace App\Entity;

use App\Repository\CashSessionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CashSessionRepository::class)]
#[ORM\Table(name: 'cash_sessions')]
class CashSession
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Business $business = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $openedBy = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $openedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private ?string $initialCash = '0.00';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $finalCashCounted = null;

    #[ORM\Column(type: 'json')]
    private array $totalsByPaymentMethod = [];

    public function __construct()
    {
        $this->openedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBusiness(): ?Business
    {
        return $this->business;
    }

    public function setBusiness(?Business $business): self
    {
        $this->business = $business;

        return $this;
    }

    public function getOpenedBy(): ?User
    {
        return $this->openedBy;
    }

    public function setOpenedBy(?User $openedBy): self
    {
        $this->openedBy = $openedBy;

        return $this;
    }

    public function getOpenedAt(): ?\DateTimeImmutable
    {
        return $this->openedAt;
    }

    public function setOpenedAt(\DateTimeImmutable $openedAt): self
    {
        $this->openedAt = $openedAt;

        return $this;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function setClosedAt(?\DateTimeImmutable $closedAt): self
    {
        $this->closedAt = $closedAt;

        return $this;
    }

    public function getInitialCash(): ?string
    {
        return $this->initialCash;
    }

    public function setInitialCash(string $initialCash): self
    {
        $this->initialCash = $initialCash;

        return $this;
    }

    public function getFinalCashCounted(): ?string
    {
        return $this->finalCashCounted;
    }

    public function setFinalCashCounted(?string $finalCashCounted): self
    {
        $this->finalCashCounted = $finalCashCounted;

        return $this;
    }

    public function getTotalsByPaymentMethod(): array
    {
        return $this->totalsByPaymentMethod;
    }

    public function setTotalsByPaymentMethod(array $totalsByPaymentMethod): self
    {
        $this->totalsByPaymentMethod = $totalsByPaymentMethod;

        return $this;
    }

    public function isOpen(): bool
    {
        return $this->closedAt === null;
    }
}
