<?php

namespace App\Entity;

use App\Repository\EmailPreferenceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: EmailPreferenceRepository::class)]
#[ORM\Table(name: 'email_preferences')]
#[ORM\UniqueConstraint(name: 'uniq_email_preferences_business_user', columns: ['business_id', 'user_id'])]
#[ORM\Index(name: 'idx_email_preferences_business', columns: ['business_id'])]
#[ORM\Index(name: 'idx_email_preferences_user', columns: ['user_id'])]
#[ORM\HasLifecycleCallbacks]
class EmailPreference
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Business $business = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $enabled = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $subscriptionAlertsEnabled = true;

    #[ORM\Column(options: ['default' => false])]
    private bool $reportDailyEnabled = false;

    #[ORM\Column(options: ['default' => true])]
    private bool $reportWeeklyEnabled = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $reportMonthlyEnabled = true;

    #[ORM\Column(options: ['default' => false])]
    private bool $reportAnnualEnabled = false;

    #[ORM\Column]
    #[Assert\Range(min: 0, max: 23)]
    private int $deliveryHour = 8;

    #[ORM\Column]
    #[Assert\Range(min: 0, max: 59)]
    private int $deliveryMinute = 0;

    #[ORM\Column(length: 64, options: ['default' => 'America/Argentina/Buenos_Aires'])]
    private string $timezone = 'America/Argentina/Buenos_Aires';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $updatedAt = null;

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

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;

        return $this;
    }

    public function isSubscriptionAlertsEnabled(): bool
    {
        return $this->subscriptionAlertsEnabled;
    }

    public function setSubscriptionAlertsEnabled(bool $subscriptionAlertsEnabled): self
    {
        $this->subscriptionAlertsEnabled = $subscriptionAlertsEnabled;

        return $this;
    }

    public function isReportDailyEnabled(): bool
    {
        return $this->reportDailyEnabled;
    }

    public function setReportDailyEnabled(bool $reportDailyEnabled): self
    {
        $this->reportDailyEnabled = $reportDailyEnabled;

        return $this;
    }

    public function isReportWeeklyEnabled(): bool
    {
        return $this->reportWeeklyEnabled;
    }

    public function setReportWeeklyEnabled(bool $reportWeeklyEnabled): self
    {
        $this->reportWeeklyEnabled = $reportWeeklyEnabled;

        return $this;
    }

    public function isReportMonthlyEnabled(): bool
    {
        return $this->reportMonthlyEnabled;
    }

    public function setReportMonthlyEnabled(bool $reportMonthlyEnabled): self
    {
        $this->reportMonthlyEnabled = $reportMonthlyEnabled;

        return $this;
    }

    public function isReportAnnualEnabled(): bool
    {
        return $this->reportAnnualEnabled;
    }

    public function setReportAnnualEnabled(bool $reportAnnualEnabled): self
    {
        $this->reportAnnualEnabled = $reportAnnualEnabled;

        return $this;
    }

    public function getDeliveryHour(): int
    {
        return $this->deliveryHour;
    }

    public function setDeliveryHour(int $deliveryHour): self
    {
        $this->deliveryHour = $deliveryHour;

        return $this;
    }

    public function getDeliveryMinute(): int
    {
        return $this->deliveryMinute;
    }

    public function setDeliveryMinute(int $deliveryMinute): self
    {
        $this->deliveryMinute = $deliveryMinute;

        return $this;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    public function setTimezone(string $timezone): self
    {
        $this->timezone = $timezone;

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

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $now = new \DateTimeImmutable();
        $this->createdAt ??= $now;
        $this->updatedAt = $now;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
