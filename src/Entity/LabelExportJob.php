<?php

namespace App\Entity;

use App\Repository\LabelExportJobRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LabelExportJobRepository::class)]
#[ORM\Table(name: 'label_export_jobs')]
class LabelExportJob
{
    public const STATUS_QUEUED = 'QUEUED';
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

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $createdBy = null;

    #[ORM\Column(length: 16)]
    private string $status = self::STATUS_QUEUED;

    #[ORM\Column]
    private int $totalProducts = 0;

    #[ORM\Column]
    private int $totalBatches = 0;

    #[ORM\Column]
    private int $doneBatches = 0;

    #[ORM\Column]
    private int $progressPercent = 0;

    #[ORM\Column(length: 255)]
    private string $progressText = 'En cola';

    #[ORM\Column]
    private int $batchSize = 50;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $zipFilename = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: Types::JSON)]
    private array $filters = [];

    /** @var Collection<int, LabelExportBatch> */
    #[ORM\OneToMany(mappedBy: 'job', targetEntity: LabelExportBatch::class, orphanRemoval: true)]
    #[ORM\OrderBy(['batchIndex' => 'ASC'])]
    private Collection $batches;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->expiresAt = $this->createdAt->modify('+12 hours');
        $this->batches = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getBusiness(): ?Business { return $this->business; }
    public function setBusiness(?Business $business): self { $this->business = $business; return $this; }
    public function getCreatedBy(): ?User { return $this->createdBy; }
    public function setCreatedBy(?User $createdBy): self { $this->createdBy = $createdBy; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }
    public function getTotalProducts(): int { return $this->totalProducts; }
    public function setTotalProducts(int $totalProducts): self { $this->totalProducts = $totalProducts; return $this; }
    public function getTotalBatches(): int { return $this->totalBatches; }
    public function setTotalBatches(int $totalBatches): self { $this->totalBatches = $totalBatches; return $this; }
    public function getDoneBatches(): int { return $this->doneBatches; }
    public function setDoneBatches(int $doneBatches): self { $this->doneBatches = $doneBatches; return $this; }
    public function getProgressPercent(): int { return $this->progressPercent; }
    public function setProgressPercent(int $progressPercent): self { $this->progressPercent = max(0, min(100, $progressPercent)); return $this; }
    public function getProgressText(): string { return $this->progressText; }
    public function setProgressText(string $progressText): self { $this->progressText = $progressText; return $this; }
    public function getBatchSize(): int { return $this->batchSize; }
    public function setBatchSize(int $batchSize): self { $this->batchSize = max(1, $batchSize); return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getStartedAt(): ?\DateTimeImmutable { return $this->startedAt; }
    public function setStartedAt(?\DateTimeImmutable $startedAt): self { $this->startedAt = $startedAt; return $this; }
    public function getFinishedAt(): ?\DateTimeImmutable { return $this->finishedAt; }
    public function setFinishedAt(?\DateTimeImmutable $finishedAt): self { $this->finishedAt = $finishedAt; return $this; }
    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }
    public function setExpiresAt(\DateTimeImmutable $expiresAt): self { $this->expiresAt = $expiresAt; return $this; }
    public function getZipFilename(): ?string { return $this->zipFilename; }
    public function setZipFilename(?string $zipFilename): self { $this->zipFilename = $zipFilename; return $this; }
    public function getErrorMessage(): ?string { return $this->errorMessage; }
    public function setErrorMessage(?string $errorMessage): self { $this->errorMessage = $errorMessage; return $this; }
    public function getFilters(): array { return $this->filters; }
    public function setFilters(array $filters): self { $this->filters = $filters; return $this; }

    /** @return Collection<int, LabelExportBatch> */
    public function getBatches(): Collection { return $this->batches; }
}
