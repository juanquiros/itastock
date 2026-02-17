<?php

namespace App\Entity;

use App\Repository\LabelExportBatchRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LabelExportBatchRepository::class)]
#[ORM\Table(name: 'label_export_batch')]
#[ORM\Index(name: 'idx_label_export_batch_job_index', columns: ['job_id', 'batch_index'])]
class LabelExportBatch
{
    public const STATUS_READY = 'READY';
    public const STATUS_FAILED = 'FAILED';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'batches')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?LabelExportJob $job = null;

    #[ORM\Column(name: 'batch_index')]
    private int $batchIndex = 1;

    #[ORM\Column(nullable: true)]
    private ?int $fromProductId = null;

    #[ORM\Column(nullable: true)]
    private ?int $toProductId = null;

    #[ORM\Column]
    private int $productsCount = 0;

    #[ORM\Column(length: 255)]
    private string $filename;

    #[ORM\Column(length: 16)]
    private string $status = self::STATUS_READY;

    public function __construct()
    {
        $this->filename = '';
    }

    public function getId(): ?int { return $this->id; }
    public function getJob(): ?LabelExportJob { return $this->job; }
    public function setJob(?LabelExportJob $job): self { $this->job = $job; return $this; }
    public function getBatchIndex(): int { return $this->batchIndex; }
    public function setBatchIndex(int $batchIndex): self { $this->batchIndex = $batchIndex; return $this; }
    public function getFromProductId(): ?int { return $this->fromProductId; }
    public function setFromProductId(?int $fromProductId): self { $this->fromProductId = $fromProductId; return $this; }
    public function getToProductId(): ?int { return $this->toProductId; }
    public function setToProductId(?int $toProductId): self { $this->toProductId = $toProductId; return $this; }
    public function getProductsCount(): int { return $this->productsCount; }
    public function setProductsCount(int $productsCount): self { $this->productsCount = $productsCount; return $this; }
    public function getFilename(): string { return $this->filename; }
    public function setFilename(string $filename): self { $this->filename = $filename; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }
}
