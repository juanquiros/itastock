<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'quotation_items')]
class QuotationItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Quotation $quotation = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Product $product = null;

    #[ORM\Column(type: 'text')]
    private ?string $description = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 3, options: ['default' => '0.000'])]
    private string $qty = '0.000';

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $unitPrice = '0.00';

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $lineSubtotal = '0.00';

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $lineDiscount = '0.00';

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $lineTotal = '0.00';

    #[ORM\Column(type: 'decimal', precision: 5, scale: 2, nullable: true)]
    private ?string $ivaRate = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getQuotation(): ?Quotation
    {
        return $this->quotation;
    }

    public function setQuotation(?Quotation $quotation): self
    {
        $this->quotation = $quotation;

        return $this;
    }

    public function getProduct(): ?Product
    {
        return $this->product;
    }

    public function setProduct(?Product $product): self
    {
        $this->product = $product;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getQty(): string
    {
        return $this->qty;
    }

    public function setQty(string $qty): self
    {
        $this->qty = bcadd($qty, '0', 3);

        return $this;
    }

    public function getUnitPrice(): string
    {
        return $this->unitPrice;
    }

    public function setUnitPrice(string $unitPrice): self
    {
        $this->unitPrice = $unitPrice;

        return $this;
    }

    public function getLineSubtotal(): string
    {
        return $this->lineSubtotal;
    }

    public function setLineSubtotal(string $lineSubtotal): self
    {
        $this->lineSubtotal = $lineSubtotal;

        return $this;
    }

    public function getLineDiscount(): string
    {
        return $this->lineDiscount;
    }

    public function setLineDiscount(string $lineDiscount): self
    {
        $this->lineDiscount = $lineDiscount;

        return $this;
    }

    public function getLineTotal(): string
    {
        return $this->lineTotal;
    }

    public function setLineTotal(string $lineTotal): self
    {
        $this->lineTotal = $lineTotal;

        return $this;
    }

    public function getIvaRate(): ?string
    {
        return $this->ivaRate;
    }

    public function setIvaRate(?string $ivaRate): self
    {
        $this->ivaRate = $ivaRate !== null ? number_format((float) $ivaRate, 2, '.', '') : null;

        return $this;
    }
}
