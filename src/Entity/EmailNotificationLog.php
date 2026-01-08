<?php

namespace App\Entity;

use App\Repository\EmailNotificationLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EmailNotificationLogRepository::class)]
#[ORM\Table(name: 'email_notification_log')]
#[ORM\UniqueConstraint(
    name: 'uniq_email_notification_log_period',
    columns: ['type', 'recipient_email', 'period_start', 'period_end']
)]
#[ORM\Index(name: 'idx_email_notification_log_business', columns: ['business_id'])]
#[ORM\Index(name: 'idx_email_notification_log_subscription', columns: ['subscription_id'])]
class EmailNotificationLog
{
    public const STATUS_SENT = 'SENT';
    public const STATUS_SKIPPED = 'SKIPPED';
    public const STATUS_FAILED = 'FAILED';

    public const ROLE_ADMIN = 'ADMIN';
    public const ROLE_SELLER = 'SELLER';
    public const ROLE_PLATFORM = 'PLATFORM';
    public const ROLE_PUBLIC = 'PUBLIC';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $type;

    #[ORM\Column(length: 180)]
    private string $recipientEmail;

    #[ORM\Column(length: 20)]
    private string $recipientRole;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Business $business = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Subscription $subscription = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $periodStart = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $periodEnd = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $contextHash = null;

    #[ORM\Column(length: 16)]
    private string $status;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getRecipientEmail(): string
    {
        return $this->recipientEmail;
    }

    public function setRecipientEmail(string $recipientEmail): self
    {
        $this->recipientEmail = $recipientEmail;

        return $this;
    }

    public function getRecipientRole(): string
    {
        return $this->recipientRole;
    }

    public function setRecipientRole(string $recipientRole): self
    {
        $this->recipientRole = $recipientRole;

        return $this;
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

    public function getSubscription(): ?Subscription
    {
        return $this->subscription;
    }

    public function setSubscription(?Subscription $subscription): self
    {
        $this->subscription = $subscription;

        return $this;
    }

    public function getPeriodStart(): ?\DateTimeImmutable
    {
        return $this->periodStart;
    }

    public function setPeriodStart(?\DateTimeImmutable $periodStart): self
    {
        $this->periodStart = $periodStart;

        return $this;
    }

    public function getPeriodEnd(): ?\DateTimeImmutable
    {
        return $this->periodEnd;
    }

    public function setPeriodEnd(?\DateTimeImmutable $periodEnd): self
    {
        $this->periodEnd = $periodEnd;

        return $this;
    }

    public function getContextHash(): ?string
    {
        return $this->contextHash;
    }

    public function setContextHash(?string $contextHash): self
    {
        $this->contextHash = $contextHash;

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

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): self
    {
        $this->errorMessage = $errorMessage;

        return $this;
    }

    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function setSentAt(?\DateTimeImmutable $sentAt): self
    {
        $this->sentAt = $sentAt;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }
}
