<?php

namespace App\Entity;

use App\Repository\MercadoPagoSubscriptionLinkRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MercadoPagoSubscriptionLinkRepository::class)]
#[ORM\Table(name: 'mercado_pago_subscription_links')]
#[ORM\Index(name: 'idx_mp_subscription_link_business', columns: ['business_id'])]
#[ORM\HasLifecycleCallbacks]
class MercadoPagoSubscriptionLink
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'mercadoPagoSubscriptionLinks')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Business $business = null;

    #[ORM\Column(length: 128, unique: true)]
    private string $mpPreapprovalId;

    #[ORM\Column(length: 32)]
    private string $status;

    #[ORM\Column(options: ['default' => false])]
    private bool $isPrimary = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct(string $mpPreapprovalId, string $status)
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->mpPreapprovalId = $mpPreapprovalId;
        $this->status = $status;
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
        if ($this->isPrimary && $business instanceof Business) {
            foreach ($business->getMercadoPagoSubscriptionLinks() as $link) {
                if ($link !== $this && $link->isPrimary()) {
                    $link->isPrimary = false;
                }
            }
        }

        return $this;
    }

    public function getMpPreapprovalId(): string
    {
        return $this->mpPreapprovalId;
    }

    public function setMpPreapprovalId(string $mpPreapprovalId): self
    {
        $this->mpPreapprovalId = $mpPreapprovalId;

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

    public function isPrimary(): bool
    {
        return $this->isPrimary;
    }

    public function setIsPrimary(bool $isPrimary): self
    {
        if ($isPrimary && $this->business instanceof Business) {
            foreach ($this->business->getMercadoPagoSubscriptionLinks() as $link) {
                if ($link !== $this && $link->isPrimary()) {
                    $link->isPrimary = false;
                }
            }
        }

        $this->isPrimary = $isPrimary;

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

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
