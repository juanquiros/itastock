<?php

namespace App\Entity;

use App\Repository\LabelExportBatchRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LabelExportBatchRepository::class)]
#[ORM\Table(name: 'label_export_batches')]
class LabelExportBatch
{
    public const STATUS_QUEUED = 'QUEUED';
    public const STATUS_READY = 'READY';
    public const STATUS_FAILED = 'FAILED';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'batches')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?LabelExportJob $job = null;

    #[ORM\Column]
    private int $batchIndex = 0;

    #[ORM\Column]
    private int $productCount = 0;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $filename = null;

    #[ORM\Column(length: 16)]
    private string $status = self::STATUS_QUEUED;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getJob(): ?LabelExportJob { return $this->job; }
    public function setJob(?LabelExportJob $job): self { $this->job = $job; return $this; }
    public function getBatchIndex(): int { return $this->batchIndex; }
    public function setBatchIndex(int $batchIndex): self { $this->batchIndex = $batchIndex; return $this; }
    public function getProductCount(): int { return $this->productCount; }
    public function setProductCount(int $productCount): self { $this->productCount = $productCount; return $this; }
    public function getFilename(): ?string { return $this->filename; }
    public function setFilename(?string $filename): self { $this->filename = $filename; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
