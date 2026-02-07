<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'purchase_orders')]
#[ORM\HasLifecycleCallbacks]
class PurchaseOrder
{
    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_CONFIRMED = 'CONFIRMED';
    public const STATUS_RECEIVED = 'RECEIVED';
    public const STATUS_CANCELLED = 'CANCELLED';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Business $business = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Supplier $supplier = null;

    #[ORM\Column(length: 20)]
    private ?string $status = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $emailSentAt = null;

    /** @var Collection<int, PurchaseOrderItem> */
    #[ORM\OneToMany(mappedBy: 'purchaseOrder', targetEntity: PurchaseOrderItem::class, orphanRemoval: true, cascade: ['persist'])]
    private Collection $items;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->status = self::STATUS_DRAFT;
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

    public function getSupplier(): ?Supplier
    {
        return $this->supplier;
    }

    public function setSupplier(?Supplier $supplier): self
    {
        $this->supplier = $supplier;

        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): self
    {
        $this->notes = $notes;

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

    public function getEmailSentAt(): ?\DateTimeImmutable
    {
        return $this->emailSentAt;
    }

    public function setEmailSentAt(?\DateTimeImmutable $emailSentAt): self
    {
        $this->emailSentAt = $emailSentAt;

        return $this;
    }

    /**
     * @return Collection<int, PurchaseOrderItem>
     */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(PurchaseOrderItem $item): self
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->setPurchaseOrder($this);
        }

        return $this;
    }

    public function removeItem(PurchaseOrderItem $item): self
    {
        if ($this->items->removeElement($item)) {
            if ($item->getPurchaseOrder() === $this) {
                $item->setPurchaseOrder(null);
            }
        }

        return $this;
    }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
        if ($this->createdAt === null) {
            $this->createdAt = $this->updatedAt;
        }
    }
}
