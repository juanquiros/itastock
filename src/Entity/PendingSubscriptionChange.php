<?php

namespace App\Entity;

use App\Repository\PendingSubscriptionChangeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: PendingSubscriptionChangeRepository::class)]
#[ORM\Table(name: 'pending_subscription_changes')]
#[ORM\Index(name: 'idx_pending_subscription_change_business', columns: ['business_id'])]
#[ORM\Index(name: 'idx_pending_subscription_change_status', columns: ['status'])]
#[ORM\HasLifecycleCallbacks]
class PendingSubscriptionChange
{
    public const TYPE_UPGRADE = 'UPGRADE';
    public const TYPE_DOWNGRADE = 'DOWNGRADE';
    public const TYPE_RENEWAL = 'RENEWAL';

    public const STATUS_CREATED = 'CREATED';
    public const STATUS_CHECKOUT_STARTED = 'CHECKOUT_STARTED';
    public const STATUS_PAID = 'PAID';
    public const STATUS_CANCELED = 'CANCELED';
    public const STATUS_EXPIRED = 'EXPIRED';
    public const STATUS_APPLIED = 'APPLIED';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Business $business = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Subscription $currentSubscription = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?BillingPlan $targetBillingPlan = null;

    #[ORM\Column(length: 16)]
    #[Assert\Choice(choices: [
        self::TYPE_UPGRADE,
        self::TYPE_DOWNGRADE,
        self::TYPE_RENEWAL,
    ])]
    private string $type;

    #[ORM\Column(length: 24)]
    #[Assert\Choice(choices: [
        self::STATUS_CREATED,
        self::STATUS_CHECKOUT_STARTED,
        self::STATUS_PAID,
        self::STATUS_CANCELED,
        self::STATUS_EXPIRED,
        self::STATUS_APPLIED,
    ])]
    private string $status = self::STATUS_CREATED;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $effectiveAt = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $mpPreapprovalId = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $externalReference = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $initPoint = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $paidAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $appliedAt = null;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
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

    public function getCurrentSubscription(): ?Subscription
    {
        return $this->currentSubscription;
    }

    public function setCurrentSubscription(Subscription $currentSubscription): self
    {
        $this->currentSubscription = $currentSubscription;

        return $this;
    }

    public function getTargetBillingPlan(): ?BillingPlan
    {
        return $this->targetBillingPlan;
    }

    public function setTargetBillingPlan(BillingPlan $targetBillingPlan): self
    {
        $this->targetBillingPlan = $targetBillingPlan;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;

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

    public function getEffectiveAt(): ?\DateTimeImmutable
    {
        return $this->effectiveAt;
    }

    public function setEffectiveAt(?\DateTimeImmutable $effectiveAt): self
    {
        $this->effectiveAt = $effectiveAt;

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

    public function getExternalReference(): ?string
    {
        return $this->externalReference;
    }

    public function setExternalReference(?string $externalReference): self
    {
        $this->externalReference = $externalReference;

        return $this;
    }

    public function getInitPoint(): ?string
    {
        return $this->initPoint;
    }

    public function setInitPoint(?string $initPoint): self
    {
        $this->initPoint = $initPoint;

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

    public function getPaidAt(): ?\DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function setPaidAt(?\DateTimeImmutable $paidAt): self
    {
        $this->paidAt = $paidAt;

        return $this;
    }

    public function getAppliedAt(): ?\DateTimeImmutable
    {
        return $this->appliedAt;
    }

    public function setAppliedAt(?\DateTimeImmutable $appliedAt): self
    {
        $this->appliedAt = $appliedAt;

        return $this;
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
