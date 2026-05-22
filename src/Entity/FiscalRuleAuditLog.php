<?php

namespace App\Entity;

use App\Repository\FiscalRuleAuditLogRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FiscalRuleAuditLogRepository::class)]
#[ORM\Table(name: 'fiscal_rule_audit_logs')]
class FiscalRuleAuditLog
{
    public const ACTION_CREATED = 'CREATED';
    public const ACTION_UPDATED = 'UPDATED';
    public const ACTION_TOGGLED = 'TOGGLED';
    public const ACTION_DELETED = 'DELETED';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false)] private ?Business $business = null;
    #[ORM\ManyToOne, ORM\JoinColumn(onDelete: 'SET NULL')] private ?FiscalRule $fiscalRule = null;
    #[ORM\ManyToOne, ORM\JoinColumn(onDelete: 'SET NULL')] private ?User $user = null;
    #[ORM\Column(length: 40)] private string $action = self::ACTION_UPDATED;
    #[ORM\Column(length: 160, nullable: true)] private ?string $ruleName = null;
    #[ORM\Column(type: 'json', nullable: true)] private ?array $beforeData = null;
    #[ORM\Column(type: 'json', nullable: true)] private ?array $afterData = null;
    #[ORM\Column(type: 'datetime_immutable')] private DateTimeImmutable $createdAt;

    public function __construct() { $this->createdAt = new DateTimeImmutable(); }
    public function setBusiness(?Business $business): self { $this->business = $business; return $this; }
    public function setFiscalRule(?FiscalRule $fiscalRule): self { $this->fiscalRule = $fiscalRule; return $this; }
    public function setUser(?User $user): self { $this->user = $user; return $this; }
    public function setAction(string $action): self { $this->action = $action; return $this; }
    public function setRuleName(?string $ruleName): self { $this->ruleName = $ruleName; return $this; }
    public function setBeforeData(?array $beforeData): self { $this->beforeData = $beforeData; return $this; }
    public function setAfterData(?array $afterData): self { $this->afterData = $afterData; return $this; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
    public function getUser(): ?User { return $this->user; }
    public function getAction(): string { return $this->action; }
    public function getRuleName(): ?string { return $this->ruleName; }
    public function getBeforeData(): ?array { return $this->beforeData; }
    public function getAfterData(): ?array { return $this->afterData; }
}
