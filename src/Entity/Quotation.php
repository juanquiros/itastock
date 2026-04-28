<?php

namespace App\Entity;

use App\Repository\QuotationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: QuotationRepository::class)]
class Quotation
{
    public const STATUS_ACTIVE = 'ACTIVE';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Business $business = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Customer $customer = null;

    #[ORM\Column(nullable: true)]
    private ?int $priceListIdUsed = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $priceListNameUsed = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $subtotal = '0.00';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $total = '0.00';

    #[ORM\Column(length: 16, options: ['default' => self::STATUS_ACTIVE])]
    private string $status = self::STATUS_ACTIVE;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $cashierComment = null;

    /** @var Collection<int, QuotationItem> */
    #[ORM\OneToMany(mappedBy: 'quotation', targetEntity: QuotationItem::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $items;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->items = new ArrayCollection();
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

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

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

    public function getCustomer(): ?Customer
    {
        return $this->customer;
    }

    public function setCustomer(?Customer $customer): self
    {
        $this->customer = $customer;

        return $this;
    }

    public function getPriceListIdUsed(): ?int
    {
        return $this->priceListIdUsed;
    }

    public function setPriceListIdUsed(?int $priceListIdUsed): self
    {
        $this->priceListIdUsed = $priceListIdUsed;

        return $this;
    }

    public function getPriceListNameUsed(): ?string
    {
        return $this->priceListNameUsed;
    }

    public function setPriceListNameUsed(?string $priceListNameUsed): self
    {
        $this->priceListNameUsed = $priceListNameUsed;

        return $this;
    }

    public function getSubtotal(): string
    {
        return $this->subtotal;
    }

    public function setSubtotal(string $subtotal): self
    {
        $this->subtotal = $subtotal;

        return $this;
    }

    public function getTotal(): string
    {
        return $this->total;
    }

    public function setTotal(string $total): self
    {
        $this->total = $total;

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

    public function getCashierComment(): ?string
    {
        return $this->cashierComment;
    }

    public function setCashierComment(?string $cashierComment): self
    {
        $this->cashierComment = $cashierComment;

        return $this;
    }

    /** @return Collection<int, QuotationItem> */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(QuotationItem $item): self
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->setQuotation($this);
        }

        return $this;
    }

    public function removeItem(QuotationItem $item): self
    {
        if ($this->items->removeElement($item) && $item->getQuotation() === $this) {
            $item->setQuotation(null);
        }

        return $this;
    }

    public function getCommercialNumber(): string
    {
        return sprintf('PRES-%08d', $this->getId() ?? 0);
    }
}
