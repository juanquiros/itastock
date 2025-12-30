<?php

namespace App\Entity;

use App\Repository\BillingWebhookEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BillingWebhookEventRepository::class)]
#[ORM\Table(name: 'billing_webhook_events')]
#[ORM\Index(name: 'idx_billing_webhook_event_event_id', columns: ['event_id'])]
#[ORM\Index(name: 'idx_billing_webhook_event_resource_id', columns: ['resource_id'])]
class BillingWebhookEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $eventId = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $resourceId = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $payload;

    #[ORM\Column(type: Types::TEXT)]
    private string $headers;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $receivedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $processedAt = null;

    public function __construct(string $payload, string $headers)
    {
        $this->payload = $payload;
        $this->headers = $headers;
        $this->receivedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEventId(): ?string
    {
        return $this->eventId;
    }

    public function setEventId(?string $eventId): self
    {
        $this->eventId = $eventId;

        return $this;
    }

    public function getResourceId(): ?string
    {
        return $this->resourceId;
    }

    public function setResourceId(?string $resourceId): self
    {
        $this->resourceId = $resourceId;

        return $this;
    }

    public function getPayload(): string
    {
        return $this->payload;
    }

    public function setPayload(string $payload): self
    {
        $this->payload = $payload;

        return $this;
    }

    public function getHeaders(): string
    {
        return $this->headers;
    }

    public function getReceivedAt(): ?\DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function getProcessedAt(): ?\DateTimeImmutable
    {
        return $this->processedAt;
    }

    public function setProcessedAt(?\DateTimeImmutable $processedAt): self
    {
        $this->processedAt = $processedAt;

        return $this;
    }
}
