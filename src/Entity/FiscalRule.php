<?php

namespace App\Entity;

use App\Repository\FiscalRuleRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: FiscalRuleRepository::class)]
#[ORM\Table(name: 'fiscal_rules')]
#[ORM\HasLifecycleCallbacks]
class FiscalRule
{
    public const APPLIES_TO_GLOBAL = 'GLOBAL';
    public const APPLIES_TO_PRODUCT = 'PRODUCT';
    public const APPLIES_TO_CATEGORY = 'CATEGORY';
    public const APPLIES_TO_CUSTOMER = 'CUSTOMER';
    public const APPLIES_TO_CUSTOMER_IVA_CONDITION = 'CUSTOMER_IVA_CONDITION';

    public const TAXABLE_BASE_SALE_NET = 'SALE_NET';
    public const TAXABLE_BASE_SALE_TOTAL = 'SALE_TOTAL';
    public const TAXABLE_BASE_ITEM_NET = 'ITEM_NET';
    public const TAXABLE_BASE_MANUAL_BASE = 'MANUAL_BASE';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false)] private ?Business $business = null;
    #[Assert\NotBlank] #[ORM\Column(length: 160)] private string $name = '';
    #[ORM\Column(options: ['default' => true])] private bool $active = true;
    #[Assert\Choice(choices: [FiscalComponent::TYPE_INTERNAL_TAX, FiscalComponent::TYPE_IIBB_PERCEPTION, FiscalComponent::TYPE_VAT_PERCEPTION, FiscalComponent::TYPE_MUNICIPAL_TAX, FiscalComponent::TYPE_NATIONAL_OTHER_TAX, FiscalComponent::TYPE_OTHER])]
    #[ORM\Column(length: 40)] private string $componentType = FiscalComponent::TYPE_OTHER;
    #[ORM\Column(options: ['default' => 100])] private int $priority = 100;
    #[Assert\Choice(choices: [self::APPLIES_TO_GLOBAL,self::APPLIES_TO_PRODUCT,self::APPLIES_TO_CATEGORY,self::APPLIES_TO_CUSTOMER,self::APPLIES_TO_CUSTOMER_IVA_CONDITION])]
    #[ORM\Column(length: 30)] private string $appliesTo = self::APPLIES_TO_GLOBAL;
    #[ORM\ManyToOne, ORM\JoinColumn(onDelete: 'CASCADE')] private ?Product $product = null;
    #[ORM\ManyToOne, ORM\JoinColumn(onDelete: 'CASCADE')] private ?Category $category = null;
    #[ORM\ManyToOne, ORM\JoinColumn(onDelete: 'CASCADE')] private ?Customer $customer = null;
    #[ORM\Column(nullable: true)] private ?int $customerIvaConditionId = null;
    #[ORM\Column(length: 80, nullable: true)] private ?string $jurisdiction = null;
    #[ORM\Column(length: 255, nullable: true)] private ?string $descriptionTemplate = null;
    #[Assert\Choice(choices: [self::TAXABLE_BASE_SALE_NET,self::TAXABLE_BASE_SALE_TOTAL,self::TAXABLE_BASE_ITEM_NET,self::TAXABLE_BASE_MANUAL_BASE])]
    #[ORM\Column(length: 40, options: ['default' => self::TAXABLE_BASE_SALE_NET])] private string $taxableBaseMode = self::TAXABLE_BASE_SALE_NET;
    #[Assert\GreaterThanOrEqual(0)] #[ORM\Column(type: 'decimal', precision: 7, scale: 4, nullable: true)] private ?string $rate = null;
    #[Assert\GreaterThanOrEqual(0)] #[ORM\Column(type: 'decimal', precision: 12, scale: 2, nullable: true)] private ?string $fixedAmount = null;
    #[Assert\GreaterThanOrEqual(0)] #[ORM\Column(type: 'decimal', precision: 12, scale: 2, nullable: true)] private ?string $minAmount = null;
    #[Assert\GreaterThanOrEqual(0)] #[ORM\Column(type: 'decimal', precision: 12, scale: 2, nullable: true)] private ?string $maxAmount = null;
    #[ORM\Column(nullable: true)] private ?int $arcaTributeId = null;
    #[ORM\Column(options: ['default' => true])] private bool $reportToArca = true;
    #[ORM\Column(options: ['default' => true])] private bool $affectsTotal = true;
    #[ORM\Column(options: ['default' => false])] private bool $includedInPrice = false;
    #[ORM\Column(type: 'date', nullable: true)] private ?\DateTimeInterface $startsAt = null;
    #[ORM\Column(type: 'date', nullable: true)] private ?\DateTimeInterface $endsAt = null;
    #[ORM\Column(options: ['default' => false])] private bool $stopProcessing = false;
    #[ORM\Column(type: 'json', nullable: true)] private ?array $metadata = null;
    #[ORM\Column(type: 'datetime_immutable')] private ?DateTimeImmutable $createdAt = null;
    #[ORM\Column(type: 'datetime_immutable')] private ?DateTimeImmutable $updatedAt = null;
    #[ORM\PrePersist] public function onPrePersist(): void { $this->createdAt ??= new DateTimeImmutable(); $this->updatedAt = new DateTimeImmutable(); }
    #[ORM\PreUpdate] public function onPreUpdate(): void { $this->updatedAt = new DateTimeImmutable(); }
    #[Assert\Callback] public function validate(ExecutionContextInterface $context): void { if($this->rate===null && $this->fixedAmount===null){$context->buildViolation('Debe informar alícuota o monto fijo.')->atPath('rate')->addViolation();} if($this->startsAt&&$this->endsAt&&$this->startsAt>$this->endsAt){$context->buildViolation('La vigencia desde no puede ser mayor a hasta.')->atPath('startsAt')->addViolation();} if($this->appliesTo===self::APPLIES_TO_PRODUCT && !$this->product){$context->buildViolation('Debe seleccionar producto.')->atPath('product')->addViolation();} if($this->appliesTo===self::APPLIES_TO_CATEGORY && !$this->category){$context->buildViolation('Debe seleccionar categoría.')->atPath('category')->addViolation();} if($this->appliesTo===self::APPLIES_TO_CUSTOMER && !$this->customer){$context->buildViolation('Debe seleccionar cliente.')->atPath('customer')->addViolation();} if($this->appliesTo===self::APPLIES_TO_CUSTOMER_IVA_CONDITION && $this->customerIvaConditionId===null){$context->buildViolation('Debe informar condición IVA.')->atPath('customerIvaConditionId')->addViolation();} }
    public function getId(): ?int { return $this->id; } public function getBusiness(): ?Business { return $this->business; } public function setBusiness(?Business $business): self { $this->business = $business; return $this; }
    public function getName(): string { return $this->name; } public function setName(string $name): self { $this->name = trim($name); return $this; }
    public function isActive(): bool { return $this->active; } public function setActive(bool $active): self { $this->active = $active; return $this; }
    public function getComponentType(): string { return $this->componentType; } public function setComponentType(string $componentType): self { $this->componentType = $componentType; return $this; }
    public function getPriority(): int { return $this->priority; } public function setPriority(int $priority): self { $this->priority = $priority; return $this; }
    public function getAppliesTo(): string { return $this->appliesTo; } public function setAppliesTo(string $appliesTo): self { $this->appliesTo = $appliesTo; return $this; }
    public function getProduct(): ?Product { return $this->product; } public function setProduct(?Product $product): self { $this->product = $product; return $this; }
    public function getCategory(): ?Category { return $this->category; } public function setCategory(?Category $category): self { $this->category = $category; return $this; }
    public function getCustomer(): ?Customer { return $this->customer; } public function setCustomer(?Customer $customer): self { $this->customer = $customer; return $this; }
    public function getCustomerIvaConditionId(): ?int { return $this->customerIvaConditionId; } public function setCustomerIvaConditionId(?int $customerIvaConditionId): self { $this->customerIvaConditionId = $customerIvaConditionId; return $this; }
    public function getJurisdiction(): ?string { return $this->jurisdiction; } public function setJurisdiction(?string $jurisdiction): self { $this->jurisdiction = $jurisdiction; return $this; }
    public function getDescriptionTemplate(): ?string { return $this->descriptionTemplate; } public function setDescriptionTemplate(?string $descriptionTemplate): self { $this->descriptionTemplate = $descriptionTemplate; return $this; }
    public function getTaxableBaseMode(): string { return $this->taxableBaseMode; } public function setTaxableBaseMode(string $taxableBaseMode): self { $this->taxableBaseMode = $taxableBaseMode; return $this; }
    public function getRate(): ?string { return $this->rate; } public function setRate(?string $rate): self { $this->rate = $rate===null?null:bcadd($rate,'0',4); return $this; }
    public function getFixedAmount(): ?string { return $this->fixedAmount; } public function setFixedAmount(?string $fixedAmount): self { $this->fixedAmount = $fixedAmount===null?null:bcadd($fixedAmount,'0',2); return $this; }
    public function getMinAmount(): ?string { return $this->minAmount; } public function setMinAmount(?string $minAmount): self { $this->minAmount = $minAmount===null?null:bcadd($minAmount,'0',2); return $this; }
    public function getMaxAmount(): ?string { return $this->maxAmount; } public function setMaxAmount(?string $maxAmount): self { $this->maxAmount = $maxAmount===null?null:bcadd($maxAmount,'0',2); return $this; }
    public function getArcaTributeId(): ?int { return $this->arcaTributeId; } public function setArcaTributeId(?int $arcaTributeId): self { $this->arcaTributeId = $arcaTributeId; return $this; }
    public function isReportToArca(): bool { return $this->reportToArca; } public function setReportToArca(bool $reportToArca): self { $this->reportToArca = $reportToArca; return $this; }
    public function isAffectsTotal(): bool { return $this->affectsTotal; } public function setAffectsTotal(bool $affectsTotal): self { $this->affectsTotal = $affectsTotal; return $this; }
    public function isIncludedInPrice(): bool { return $this->includedInPrice; } public function setIncludedInPrice(bool $includedInPrice): self { $this->includedInPrice = $includedInPrice; return $this; }
    public function getStartsAt(): ?\DateTimeInterface { return $this->startsAt; } public function setStartsAt(?\DateTimeInterface $startsAt): self { $this->startsAt = $startsAt; return $this; }
    public function getEndsAt(): ?\DateTimeInterface { return $this->endsAt; } public function setEndsAt(?\DateTimeInterface $endsAt): self { $this->endsAt = $endsAt; return $this; }
    public function isStopProcessing(): bool { return $this->stopProcessing; } public function setStopProcessing(bool $stopProcessing): self { $this->stopProcessing = $stopProcessing; return $this; }
    public function getMetadata(): ?array { return $this->metadata; } public function setMetadata(?array $metadata): self { $this->metadata = $metadata; return $this; }
}
