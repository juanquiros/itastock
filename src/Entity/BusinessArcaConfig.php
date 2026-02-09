<?php

namespace App\Entity;

use App\Repository\BusinessArcaConfigRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BusinessArcaConfigRepository::class)]
#[ORM\Table(name: 'business_arca_configs')]
#[ORM\HasLifecycleCallbacks]
class BusinessArcaConfig
{
    public const ENV_HOMO = 'HOMO';
    public const ENV_PROD = 'PROD';

    public const TAX_PAYER_MONOTRIBUTO = 'MONOTRIBUTO';
    public const TAX_PAYER_RESPONSABLE_INSCRIPTO = 'RESPONSABLE_INSCRIPTO';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'arcaConfig')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Business $business = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $arcaEnabled = false;

    #[ORM\Column(length: 10, options: ['default' => self::ENV_HOMO])]
    private string $arcaEnvironment = self::ENV_HOMO;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $cuitEmisor = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $certPem = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $privateKeyPem = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $passphrase = null;

    #[ORM\Column(length: 30, options: ['default' => self::TAX_PAYER_MONOTRIBUTO])]
    private string $taxPayerType = self::TAX_PAYER_MONOTRIBUTO;

    #[ORM\Column(nullable: true)]
    private ?int $defaultReceiverIvaConditionId = null;

    #[ORM\Column]
    private ?DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?DateTimeImmutable $updatedAt = null;

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

    public function isArcaEnabled(): bool
    {
        return $this->arcaEnabled;
    }

    public function setArcaEnabled(bool $arcaEnabled): self
    {
        $this->arcaEnabled = $arcaEnabled;

        return $this;
    }

    public function getArcaEnvironment(): string
    {
        return $this->arcaEnvironment;
    }

    public function setArcaEnvironment(string $arcaEnvironment): self
    {
        $this->arcaEnvironment = $arcaEnvironment;

        return $this;
    }

    public function getCuitEmisor(): ?string
    {
        return $this->cuitEmisor;
    }

    public function setCuitEmisor(?string $cuitEmisor): self
    {
        $this->cuitEmisor = $cuitEmisor;

        return $this;
    }

    public function getCertPem(): ?string
    {
        return $this->certPem;
    }

    public function setCertPem(?string $certPem): self
    {
        $this->certPem = $certPem;

        return $this;
    }

    public function getPrivateKeyPem(): ?string
    {
        return $this->privateKeyPem;
    }

    public function setPrivateKeyPem(?string $privateKeyPem): self
    {
        $this->privateKeyPem = $privateKeyPem;

        return $this;
    }

    public function getPassphrase(): ?string
    {
        return $this->passphrase;
    }

    public function setPassphrase(?string $passphrase): self
    {
        $this->passphrase = $passphrase;

        return $this;
    }

    public function getTaxPayerType(): string
    {
        return $this->taxPayerType;
    }

    public function setTaxPayerType(string $taxPayerType): self
    {
        $this->taxPayerType = $taxPayerType;

        return $this;
    }

    public function getDefaultReceiverIvaConditionId(): ?int
    {
        return $this->defaultReceiverIvaConditionId;
    }

    public function setDefaultReceiverIvaConditionId(?int $defaultReceiverIvaConditionId): self
    {
        $this->defaultReceiverIvaConditionId = $defaultReceiverIvaConditionId;

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
}
