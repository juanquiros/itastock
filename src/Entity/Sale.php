<?php

namespace App\Entity;

use App\Repository\SaleRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: 'sales')]
#[ORM\Entity(repositoryClass: SaleRepository::class)]
class Sale
{
    public const STATUS_CONFIRMED = 'CONFIRMED';
    public const STATUS_VOIDED = 'VOIDED';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Business $business = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $voidedBy = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Customer $customer = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private ?string $total = '0.00';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private ?string $subtotal = '0.00';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private ?string $discountTotal = '0.00';

    #[ORM\Column(length: 16, options: ['default' => self::STATUS_CONFIRMED])]
    private string $status = self::STATUS_CONFIRMED;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $voidedAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $voidReason = null;

    #[ORM\Column(nullable: true)]
    private ?int $posNumber = null;

    #[ORM\Column(nullable: true)]
    private ?int $posSequence = null;

    /** @var Collection<int, SaleItem> */
    #[ORM\OneToMany(mappedBy: 'sale', targetEntity: SaleItem::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $items;

    /** @var Collection<int, Payment> */
    #[ORM\OneToMany(mappedBy: 'sale', targetEntity: Payment::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $payments;

    /** @var Collection<int, SaleDiscount> */
    #[ORM\OneToMany(mappedBy: 'sale', targetEntity: SaleDiscount::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $saleDiscounts;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->items = new ArrayCollection();
        $this->payments = new ArrayCollection();
        $this->saleDiscounts = new ArrayCollection();
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

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): self
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getVoidedBy(): ?User
    {
        return $this->voidedBy;
    }

    public function setVoidedBy(?User $voidedBy): self
    {
        $this->voidedBy = $voidedBy;

        return $this;
    }

    public function getCustomer(): ?Customer
    {
        return $this->customer;
    }

    public function setCustomer(?Customer $customer): self
    {
        $this->customer = $customer;

        return $this;
    }

    public function getTotal(): ?string
    {
        return $this->total;
    }

    public function setTotal(string $total): self
    {
        $this->total = $total;

        return $this;
    }

    public function getSubtotal(): ?string
    {
        return $this->subtotal;
    }

    public function setSubtotal(string $subtotal): self
    {
        $this->subtotal = $subtotal;

        return $this;
    }

    public function getDiscountTotal(): ?string
    {
        return $this->discountTotal;
    }

    public function setDiscountTotal(string $discountTotal): self
    {
        $this->discountTotal = $discountTotal;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

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

    public function getVoidedAt(): ?\DateTimeImmutable
    {
        return $this->voidedAt;
    }

    public function setVoidedAt(?\DateTimeImmutable $voidedAt): self
    {
        $this->voidedAt = $voidedAt;

        return $this;
    }

    public function getVoidReason(): ?string
    {
        return $this->voidReason;
    }

    public function setVoidReason(?string $voidReason): self
    {
        $this->voidReason = $voidReason;

        return $this;
    }

    public function getPosNumber(): ?int
    {
        return $this->posNumber;
    }

    public function setPosNumber(?int $posNumber): self
    {
        $this->posNumber = $posNumber;

        return $this;
    }

    public function getPosSequence(): ?int
    {
        return $this->posSequence;
    }

    public function setPosSequence(?int $posSequence): self
    {
        $this->posSequence = $posSequence;

        return $this;
    }

    /**
     * @return Collection<int, SaleItem>
     */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(SaleItem $item): self
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->setSale($this);
        }

        return $this;
    }

    public function removeItem(SaleItem $item): self
    {
        if ($this->items->removeElement($item)) {
            if ($item->getSale() === $this) {
                $item->setSale(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Payment>
     */
    public function getPayments(): Collection
    {
        return $this->payments;
    }

    /**
     * @return Collection<int, SaleDiscount>
     */
    public function getSaleDiscounts(): Collection
    {
        return $this->saleDiscounts;
    }

    public function addSaleDiscount(SaleDiscount $saleDiscount): self
    {
        if (!$this->saleDiscounts->contains($saleDiscount)) {
            $this->saleDiscounts->add($saleDiscount);
            $saleDiscount->setSale($this);
        }

        return $this;
    }

    public function removeSaleDiscount(SaleDiscount $saleDiscount): self
    {
        if ($this->saleDiscounts->removeElement($saleDiscount)) {
            if ($saleDiscount->getSale() === $this) {
                $saleDiscount->setSale(null);
            }
        }

        return $this;
    }

    public function addPayment(Payment $payment): self
    {
        if (!$this->payments->contains($payment)) {
            $this->payments->add($payment);
            $payment->setSale($this);
        }

        return $this;
    }

    public function removePayment(Payment $payment): self
    {
        if ($this->payments->removeElement($payment)) {
            if ($payment->getSale() === $this) {
                $payment->setSale(null);
            }
        }

        return $this;
    }
}
