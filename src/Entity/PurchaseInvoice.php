<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'purchase_invoices')]
class PurchaseInvoice
{
    public const TYPE_FACTURA = 'FACTURA';
    public const TYPE_TICKET = 'TICKET';
    public const TYPE_NC = 'NC';

    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_CONFIRMED = 'CONFIRMED';

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

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?PurchaseOrder $purchaseOrder = null;

    #[ORM\Column(length: 20)]
    private ?string $invoiceType = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $pointOfSale = null;

    #[ORM\Column(length: 50)]
    private ?string $invoiceNumber = null;

    #[ORM\Column(type: 'date_immutable')]
    private ?\DateTimeImmutable $invoiceDate = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $netAmount = '0.00';

    #[ORM\Column(type: 'decimal', precision: 5, scale: 2)]
    private string $ivaRate = '0.00';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $ivaAmount = '0.00';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $totalAmount = '0.00';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(length: 20)]
    private ?string $status = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->status = self::STATUS_DRAFT;
        $this->invoiceType = self::TYPE_FACTURA;
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

    public function getPurchaseOrder(): ?PurchaseOrder
    {
        return $this->purchaseOrder;
    }

    public function setPurchaseOrder(?PurchaseOrder $purchaseOrder): self
    {
        $this->purchaseOrder = $purchaseOrder;

        return $this;
    }

    public function getInvoiceType(): ?string
    {
        return $this->invoiceType;
    }

    public function setInvoiceType(string $invoiceType): self
    {
        $this->invoiceType = $invoiceType;

        return $this;
    }

    public function getPointOfSale(): ?string
    {
        return $this->pointOfSale;
    }

    public function setPointOfSale(?string $pointOfSale): self
    {
        $this->pointOfSale = $pointOfSale;

        return $this;
    }

    public function getInvoiceNumber(): ?string
    {
        return $this->invoiceNumber;
    }

    public function setInvoiceNumber(string $invoiceNumber): self
    {
        $this->invoiceNumber = $invoiceNumber;

        return $this;
    }

    public function getInvoiceDate(): ?\DateTimeImmutable
    {
        return $this->invoiceDate;
    }

    public function setInvoiceDate(\DateTimeImmutable $invoiceDate): self
    {
        $this->invoiceDate = $invoiceDate;

        return $this;
    }

    public function getNetAmount(): string
    {
        return $this->netAmount;
    }

    public function setNetAmount(string $netAmount): self
    {
        $this->netAmount = bcadd($netAmount, '0', 2);

        return $this;
    }

    public function getIvaRate(): string
    {
        return $this->ivaRate;
    }

    public function setIvaRate(string $ivaRate): self
    {
        $this->ivaRate = bcadd($ivaRate, '0', 2);

        return $this;
    }

    public function getIvaAmount(): string
    {
        return $this->ivaAmount;
    }

    public function setIvaAmount(string $ivaAmount): self
    {
        $this->ivaAmount = bcadd($ivaAmount, '0', 2);

        return $this;
    }

    public function getTotalAmount(): string
    {
        return $this->totalAmount;
    }

    public function setTotalAmount(string $totalAmount): self
    {
        $this->totalAmount = bcadd($totalAmount, '0', 2);

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

    public function getStatus(): ?string
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
}
