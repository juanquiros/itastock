<?php

namespace App\Entity;

use App\Repository\SubscriptionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: SubscriptionRepository::class)]
#[ORM\Table(name: 'subscriptions')]
#[ORM\Index(name: 'idx_subscription_mp_plan', columns: ['mp_preapproval_plan_id'])]
#[ORM\HasLifecycleCallbacks]
class Subscription
{
    public const STATUS_TRIAL = 'TRIAL';
    public const STATUS_ACTIVE = 'ACTIVE';
    public const STATUS_PAST_DUE = 'PAST_DUE';
    public const STATUS_CANCELED = 'CANCELED';
    public const STATUS_SUSPENDED = 'SUSPENDED';
    public const STATUS_PENDING = 'PENDING';
    public const OVERRIDE_FULL = 'FULL';
    public const OVERRIDE_READONLY = 'READONLY';
    public const OVERRIDE_BLOCKED = 'BLOCKED';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'subscription', targetEntity: Business::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Business $business = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Plan $plan = null;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: [
        self::STATUS_TRIAL,
        self::STATUS_ACTIVE,
        self::STATUS_PAST_DUE,
        self::STATUS_CANCELED,
        self::STATUS_SUSPENDED,
        self::STATUS_PENDING,
    ])]
    private string $status = self::STATUS_TRIAL;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $startAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $trialEndsAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(length: 128, unique: true, nullable: true)]
    private ?string $mpPreapprovalId = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $mpPreapprovalPlanId = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $payerEmail = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastSyncedAt = null;

    #[ORM\Column(length: 16, nullable: true)]
    #[Assert\Choice(choices: [
        self::OVERRIDE_FULL,
        self::OVERRIDE_READONLY,
        self::OVERRIDE_BLOCKED,
    ])]
    private ?string $overrideMode = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $overrideUntil = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $nextPaymentAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->startAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBusiness(): ?Business
    {
        return $this->business;
    }

    public function setBusiness(Business $business): self
    {
        $this->business = $business;

        return $this;
    }

    public function getPlan(): ?Plan
    {
        return $this->plan;
    }

    public function setPlan(Plan $plan): self
    {
        $this->plan = $plan;

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

    public function getStartAt(): ?\DateTimeImmutable
    {
        return $this->startAt;
    }

    public function setStartAt(\DateTimeImmutable $startAt): self
    {
        $this->startAt = $startAt;

        return $this;
    }

    public function getEndAt(): ?\DateTimeImmutable
    {
        return $this->endAt;
    }

    public function setEndAt(?\DateTimeImmutable $endAt): self
    {
        $this->endAt = $endAt;

        return $this;
    }

    public function getTrialEndsAt(): ?\DateTimeImmutable
    {
        return $this->trialEndsAt;
    }

    public function setTrialEndsAt(?\DateTimeImmutable $trialEndsAt): self
    {
        $this->trialEndsAt = $trialEndsAt;

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

    public function getMpPreapprovalId(): ?string
    {
        return $this->mpPreapprovalId;
    }

    public function setMpPreapprovalId(?string $mpPreapprovalId): self
    {
        $this->mpPreapprovalId = $mpPreapprovalId;

        return $this;
    }

    public function getMpPreapprovalPlanId(): ?string
    {
        return $this->mpPreapprovalPlanId;
    }

    public function setMpPreapprovalPlanId(?string $mpPreapprovalPlanId): self
    {
        $this->mpPreapprovalPlanId = $mpPreapprovalPlanId;

        return $this;
    }

    public function getPayerEmail(): ?string
    {
        return $this->payerEmail;
    }

    public function setPayerEmail(?string $payerEmail): self
    {
        $this->payerEmail = $payerEmail;

        return $this;
    }

    public function getLastSyncedAt(): ?\DateTimeImmutable
    {
        return $this->lastSyncedAt;
    }

    public function setLastSyncedAt(?\DateTimeImmutable $lastSyncedAt): self
    {
        $this->lastSyncedAt = $lastSyncedAt;

        return $this;
    }

    public function getOverrideMode(): ?string
    {
        return $this->overrideMode;
    }

    public function setOverrideMode(?string $overrideMode): self
    {
        $this->overrideMode = $overrideMode;

        return $this;
    }

    public function getOverrideUntil(): ?\DateTimeImmutable
    {
        return $this->overrideUntil;
    }

    public function setOverrideUntil(?\DateTimeImmutable $overrideUntil): self
    {
        $this->overrideUntil = $overrideUntil;

        return $this;
    }

    public function getNextPaymentAt(): ?\DateTimeImmutable
    {
        return $this->nextPaymentAt;
    }

    public function setNextPaymentAt(?\DateTimeImmutable $nextPaymentAt): self
    {
        $this->nextPaymentAt = $nextPaymentAt;

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
