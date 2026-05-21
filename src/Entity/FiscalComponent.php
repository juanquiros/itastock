<?php

namespace App\Entity;

use App\Repository\FiscalComponentRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: FiscalComponentRepository::class)]
#[ORM\Table(name: 'fiscal_components')]
#[ORM\HasLifecycleCallbacks]
class FiscalComponent
{
    public const SOURCE_SALE = 'SALE';
    public const SOURCE_PURCHASE_INVOICE = 'PURCHASE_INVOICE';
    public const TYPE_INTERNAL_TAX = 'INTERNAL_TAX';
    public const TYPE_IIBB_PERCEPTION = 'IIBB_PERCEPTION';
    public const TYPE_VAT_PERCEPTION = 'VAT_PERCEPTION';
    public const TYPE_MUNICIPAL_TAX = 'MUNICIPAL_TAX';
    public const TYPE_NATIONAL_OTHER_TAX = 'NATIONAL_OTHER_TAX';
    public const TYPE_OTHER = 'OTHER';
    public const MODE_MANUAL = 'MANUAL';
    public const MODE_AUTO_RULE = 'AUTO_RULE';
    public const MODE_EXTERNAL_PADRON = 'EXTERNAL_PADRON';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false)]
    private ?Business $business = null;
    #[ORM\ManyToOne(inversedBy: 'fiscalComponents'), ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?Sale $sale = null;
    #[ORM\ManyToOne(inversedBy: 'fiscalComponents'), ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?PurchaseInvoice $purchaseInvoice = null;
    #[ORM\Column(length: 30)] private string $sourceType = self::SOURCE_SALE;
    #[ORM\Column(length: 40)] private string $componentType = self::TYPE_OTHER;
    #[ORM\Column(length: 30)] private string $mode = self::MODE_MANUAL;
    #[Assert\NotBlank]
    #[ORM\Column(length: 255)] private string $description = '';
    #[ORM\Column(length: 80, nullable: true)] private ?string $jurisdiction = null;
    #[ORM\Column(nullable: true)] private ?int $arcaTributeId = null;
    #[Assert\GreaterThanOrEqual(0)]
    #[ORM\Column(type: 'decimal', precision: 12, scale: 2, options: ['default' => '0.00'])] private string $taxableBase = '0.00';
    #[Assert\GreaterThanOrEqual(0)]
    #[ORM\Column(type: 'decimal', precision: 7, scale: 4, nullable: true)] private ?string $rate = null;
    #[Assert\GreaterThanOrEqual(0)]
    #[ORM\Column(type: 'decimal', precision: 12, scale: 2, options: ['default' => '0.00'])] private string $amount = '0.00';
    #[ORM\Column(options: ['default' => true])] private bool $affectsTotal = true;
    #[ORM\Column(options: ['default' => true])] private bool $reportToArca = true;
    #[ORM\Column(options: ['default' => false])] private bool $includedInPrice = false;
    #[ORM\Column(type: 'json', nullable: true)] private ?array $metadata = null;
    #[ORM\Column(type: 'datetime_immutable')] private ?DateTimeImmutable $createdAt = null;
    #[ORM\Column(type: 'datetime_immutable')] private ?DateTimeImmutable $updatedAt = null;
    #[ORM\PrePersist] public function onPrePersist(): void { $this->createdAt ??= new DateTimeImmutable(); $this->updatedAt = new DateTimeImmutable(); }
    #[ORM\PreUpdate] public function onPreUpdate(): void { $this->updatedAt = new DateTimeImmutable(); }
    public function getId(): ?int { return $this->id; }
    public function getBusiness(): ?Business { return $this->business; }
    public function setBusiness(?Business $business): self { $this->business = $business; return $this; }
    public function getSale(): ?Sale { return $this->sale; }
    public function setSale(?Sale $sale): self { $this->sale = $sale; return $this; }
    public function getPurchaseInvoice(): ?PurchaseInvoice { return $this->purchaseInvoice; }
    public function setPurchaseInvoice(?PurchaseInvoice $purchaseInvoice): self { $this->purchaseInvoice = $purchaseInvoice; return $this; }
    public function getSourceType(): string { return $this->sourceType; }
    public function setSourceType(string $sourceType): self { $this->sourceType = $sourceType; return $this; }
    public function getComponentType(): string { return $this->componentType; }
    public function setComponentType(string $componentType): self { $this->componentType = $componentType; return $this; }
    public function getMode(): string { return $this->mode; }
    public function setMode(string $mode): self { $this->mode = $mode; return $this; }
    public function getDescription(): string { return $this->description; }
    public function setDescription(string $description): self { $this->description = trim($description); return $this; }
    public function getJurisdiction(): ?string { return $this->jurisdiction; }
    public function setJurisdiction(?string $jurisdiction): self { $this->jurisdiction = $jurisdiction; return $this; }
    public function getArcaTributeId(): ?int { return $this->arcaTributeId; }
    public function setArcaTributeId(?int $arcaTributeId): self { $this->arcaTributeId = $arcaTributeId; return $this; }
    public function getTaxableBase(): string { return $this->taxableBase; }
    public function setTaxableBase(string $taxableBase): self { $this->taxableBase = bcadd($taxableBase, '0', 2); return $this; }
    public function getRate(): ?string { return $this->rate; }
    public function setRate(?string $rate): self { $this->rate = $rate === null ? null : bcadd($rate, '0', 4); return $this; }
    public function getAmount(): string { return $this->amount; }
    public function setAmount(string $amount): self { $this->amount = bcadd($amount, '0', 2); return $this; }
    public function isAffectsTotal(): bool { return $this->affectsTotal; }
    public function setAffectsTotal(bool $affectsTotal): self { $this->affectsTotal = $affectsTotal; return $this; }
    public function isReportToArca(): bool { return $this->reportToArca; }
    public function setReportToArca(bool $reportToArca): self { $this->reportToArca = $reportToArca; return $this; }
    public function isIncludedInPrice(): bool { return $this->includedInPrice; }
    public function setIncludedInPrice(bool $includedInPrice): self { $this->includedInPrice = $includedInPrice; return $this; }
    public function getMetadata(): ?array { return $this->metadata; }
    public function setMetadata(?array $metadata): self { $this->metadata = $metadata; return $this; }
    public function getCreatedAt(): ?DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(DateTimeImmutable $createdAt): self { $this->createdAt = $createdAt; return $this; }
    public function getUpdatedAt(): ?DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(DateTimeImmutable $updatedAt): self { $this->updatedAt = $updatedAt; return $this; }
    public function touch(): self { $this->updatedAt = new DateTimeImmutable(); return $this; }
    public function toSnapshotArray(): array { return ['componentType'=>$this->componentType,'description'=>$this->description,'jurisdiction'=>$this->jurisdiction,'arcaTributeId'=>$this->arcaTributeId,'taxableBase'=>$this->taxableBase,'rate'=>$this->rate,'amount'=>$this->amount,'affectsTotal'=>$this->affectsTotal,'reportToArca'=>$this->reportToArca,'includedInPrice'=>$this->includedInPrice,'mode'=>$this->mode]; }
}
