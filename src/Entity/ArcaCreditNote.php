<?php

namespace App\Entity;

use App\Repository\ArcaCreditNoteRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ArcaCreditNoteRepository::class)]
#[ORM\Table(name: 'arca_credit_notes')]
#[ORM\HasLifecycleCallbacks]
class ArcaCreditNote
{
    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_REQUESTED = 'REQUESTED';
    public const STATUS_AUTHORIZED = 'AUTHORIZED';
    public const STATUS_REJECTED = 'REJECTED';
    public const STATUS_CANCELLED = 'CANCELLED';

    public const CBTE_NC_B = 'NC_B';
    public const CBTE_NC_C = 'NC_C';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Business $business = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, unique: true)]
    private ?Sale $sale = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?ArcaInvoice $relatedInvoice = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_DRAFT;

    #[ORM\Column]
    private int $arcaPosNumber = 1;

    #[ORM\Column(length: 30)]
    private string $cbteTipo = self::CBTE_NC_C;

    #[ORM\Column(nullable: true)]
    private ?int $cbteNumero = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $cae = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?DateTimeImmutable $caeDueDate = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $netAmount = '0.00';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $vatAmount = '0.00';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $totalAmount = '0.00';

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $itemsSnapshot = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $arcaRawRequest = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $arcaRawResponse = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $issuedAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?DateTimeImmutable $updatedAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $reason = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $now = new DateTimeImmutable();
        $this->createdAt = $this->createdAt ?? $now;
        $this->updatedAt = $now;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new DateTimeImmutable();
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

    public function getSale(): ?Sale
    {
        return $this->sale;
    }

    public function setSale(?Sale $sale): self
    {
        $this->sale = $sale;

        return $this;
    }

    public function getRelatedInvoice(): ?ArcaInvoice
    {
        return $this->relatedInvoice;
    }

    public function setRelatedInvoice(?ArcaInvoice $relatedInvoice): self
    {
        $this->relatedInvoice = $relatedInvoice;

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

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getArcaPosNumber(): int
    {
        return $this->arcaPosNumber;
    }

    public function setArcaPosNumber(int $arcaPosNumber): self
    {
        $this->arcaPosNumber = $arcaPosNumber;

        return $this;
    }

    public function getCbteTipo(): string
    {
        return $this->cbteTipo;
    }

    public function setCbteTipo(string $cbteTipo): self
    {
        $this->cbteTipo = $cbteTipo;

        return $this;
    }

    public function getCbteNumero(): ?int
    {
        return $this->cbteNumero;
    }

    public function setCbteNumero(?int $cbteNumero): self
    {
        $this->cbteNumero = $cbteNumero;

        return $this;
    }

    public function getCae(): ?string
    {
        return $this->cae;
    }

    public function setCae(?string $cae): self
    {
        $this->cae = $cae;

        return $this;
    }

    public function getCaeDueDate(): ?DateTimeImmutable
    {
        return $this->caeDueDate;
    }

    public function setCaeDueDate(?DateTimeImmutable $caeDueDate): self
    {
        $this->caeDueDate = $caeDueDate;

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

    public function getVatAmount(): string
    {
        return $this->vatAmount;
    }

    public function setVatAmount(string $vatAmount): self
    {
        $this->vatAmount = bcadd($vatAmount, '0', 2);

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

    public function getItemsSnapshot(): ?array
    {
        return $this->itemsSnapshot;
    }

    public function setItemsSnapshot(?array $itemsSnapshot): self
    {
        $this->itemsSnapshot = $itemsSnapshot;

        return $this;
    }

    public function getArcaRawRequest(): ?array
    {
        return $this->arcaRawRequest;
    }

    public function setArcaRawRequest(?array $arcaRawRequest): self
    {
        $this->arcaRawRequest = $arcaRawRequest;

        return $this;
    }

    public function getArcaRawResponse(): ?array
    {
        return $this->arcaRawResponse;
    }

    public function setArcaRawResponse(?array $arcaRawResponse): self
    {
        $this->arcaRawResponse = $arcaRawResponse;

        return $this;
    }

    public function getIssuedAt(): ?DateTimeImmutable
    {
        return $this->issuedAt;
    }

    public function setIssuedAt(?DateTimeImmutable $issuedAt): self
    {
        $this->issuedAt = $issuedAt;

        return $this;
    }

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(?string $reason): self
    {
        $this->reason = $reason;

        return $this;
    }
}
