<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'sale_discounts')]
class SaleDiscount
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'saleDiscounts')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Sale $sale = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Discount $discount = null;

    #[ORM\Column(length: 255)]
    private ?string $discountName = null;

    #[ORM\Column(length: 16)]
    private ?string $actionType = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private ?string $actionValue = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private ?string $appliedAmount = null;

    #[ORM\Column(type: 'json')]
    private array $meta = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSale(): ?Sale
    {
        return $this->sale;
    }

    public function setSale(?Sale $sale): self
    {
        $this->sale = $sale;

        return $this;
    }

    public function getDiscount(): ?Discount
    {
        return $this->discount;
    }

    public function setDiscount(?Discount $discount): self
    {
        $this->discount = $discount;

        return $this;
    }

    public function getDiscountName(): ?string
    {
        return $this->discountName;
    }

    public function setDiscountName(string $discountName): self
    {
        $this->discountName = $discountName;

        return $this;
    }

    public function getActionType(): ?string
    {
        return $this->actionType;
    }

    public function setActionType(string $actionType): self
    {
        $this->actionType = $actionType;

        return $this;
    }

    public function getActionValue(): ?string
    {
        return $this->actionValue;
    }

    public function setActionValue(string $actionValue): self
    {
        $this->actionValue = $actionValue;

        return $this;
    }

    public function getAppliedAmount(): ?string
    {
        return $this->appliedAmount;
    }

    public function setAppliedAmount(string $appliedAmount): self
    {
        $this->appliedAmount = $appliedAmount;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMeta(): array
    {
        return $this->meta;
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function setMeta(array $meta): self
    {
        $this->meta = $meta;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }
}
