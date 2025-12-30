<?php

namespace App\Entity;

use App\Repository\BillingPlanRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: BillingPlanRepository::class)]
#[ORM\Table(name: 'billing_plans')]
#[ORM\UniqueConstraint(name: 'uniq_billing_plan_mp_preapproval_plan', columns: ['mp_preapproval_plan_id'])]
#[ORM\HasLifecycleCallbacks]
class BillingPlan
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    private ?string $name = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    #[Assert\PositiveOrZero]
    private ?string $price = null;

    #[ORM\Column(length: 10)]
    #[Assert\NotBlank]
    private string $currency = 'ARS';

    #[ORM\Column]
    #[Assert\Positive]
    private int $frequency = 1;

    #[ORM\Column(length: 20)]
    #[Assert\NotBlank]
    private string $frequencyType = 'months';

    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(length: 128, unique: true, nullable: true)]
    private ?string $mpPreapprovalPlanId = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getPrice(): ?string
    {
        return $this->price;
    }

    public function setPrice(string $price): self
    {
        $this->price = $price;

        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): self
    {
        $this->currency = $currency;

        return $this;
    }

    public function getFrequency(): int
    {
        return $this->frequency;
    }

    public function setFrequency(int $frequency): self
    {
        $this->frequency = $frequency;

        return $this;
    }

    public function getFrequencyType(): string
    {
        return $this->frequencyType;
    }

    public function setFrequencyType(string $frequencyType): self
    {
        $this->frequencyType = $frequencyType;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function getMpPreapprovalPlanId(): ?string
    {
        return $this->mpPreapprovalPlanId;
    }

    public function setMpPreapprovalPlanId(?string $mpPreapprovalPlanId): self
    {
        $this->mpPreapprovalPlanId = $mpPreapprovalPlanId;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
