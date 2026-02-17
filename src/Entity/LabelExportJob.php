<?php

namespace App\Entity;

use App\Repository\LabelExportJobRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LabelExportJobRepository::class)]
#[ORM\Table(name: 'label_export_job')]
#[ORM\Index(name: 'idx_label_export_job_business_created_at', columns: ['business_id', 'created_at'])]
class LabelExportJob
{
    public const TYPE_LABELS_CATALOG = 'labels_catalog';

    public const STATUS_RUNNING = 'RUNNING';
    public const STATUS_READY = 'READY';
    public const STATUS_FAILED = 'FAILED';
    public const STATUS_EXPIRED = 'EXPIRED';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Business $business = null;

    #[ORM\Column(length: 32)]
    private string $type = self::TYPE_LABELS_CATALOG;

    #[ORM\Column(length: 16)]
    private string $status = self::STATUS_RUNNING;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdByUser = null;

    #[ORM\Column(type: Types::JSON)]
    private array $params = [];

    #[ORM\Column(length: 255)]
    private string $basePath;

    #[ORM\Column]
    private int $batchesCount = 0;

    #[ORM\Column]
    private int $totalProducts = 0;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $zipFilename = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    /** @var Collection<int, LabelExportBatch> */
    #[ORM\OneToMany(mappedBy: 'job', targetEntity: LabelExportBatch::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['batchIndex' => 'ASC'])]
    private Collection $batches;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->expiresAt = $this->createdAt->modify('+12 hours');
        $this->basePath = '';
        $this->batches = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getBusiness(): ?Business { return $this->business; }
    public function setBusiness(?Business $business): self { $this->business = $business; return $this; }
    public function getType(): string { return $this->type; }
    public function setType(string $type): self { $this->type = $type; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $createdAt): self { $this->createdAt = $createdAt; return $this; }
    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }
    public function setExpiresAt(\DateTimeImmutable $expiresAt): self { $this->expiresAt = $expiresAt; return $this; }
    public function getCreatedByUser(): ?User { return $this->createdByUser; }
    public function setCreatedByUser(?User $createdByUser): self { $this->createdByUser = $createdByUser; return $this; }
    public function getParams(): array { return $this->params; }
    public function setParams(array $params): self { $this->params = $params; return $this; }
    public function getBasePath(): string { return $this->basePath; }
    public function setBasePath(string $basePath): self { $this->basePath = $basePath; return $this; }
    public function getBatchesCount(): int { return $this->batchesCount; }
    public function setBatchesCount(int $batchesCount): self { $this->batchesCount = $batchesCount; return $this; }
    public function getTotalProducts(): int { return $this->totalProducts; }
    public function setTotalProducts(int $totalProducts): self { $this->totalProducts = $totalProducts; return $this; }
    public function getZipFilename(): ?string { return $this->zipFilename; }
    public function setZipFilename(?string $zipFilename): self { $this->zipFilename = $zipFilename; return $this; }
    public function getErrorMessage(): ?string { return $this->errorMessage; }
    public function setErrorMessage(?string $errorMessage): self { $this->errorMessage = $errorMessage; return $this; }

    /** @return Collection<int, LabelExportBatch> */
    public function getBatches(): Collection
    {
        return $this->batches;
    }

    public function addBatch(LabelExportBatch $batch): self
    {
        if (!$this->batches->contains($batch)) {
            $this->batches->add($batch);
            $batch->setJob($this);
        }

        return $this;
    }

    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable();

        return $this->expiresAt <= $now;
    }
}
