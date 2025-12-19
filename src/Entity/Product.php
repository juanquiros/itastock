<?php

namespace App\Entity;

use App\Repository\ProductRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use App\Entity\StockMovement;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[ORM\Table(name: 'products')]
#[ORM\HasLifecycleCallbacks]
class Product
{
    public const UOM_UNIT = 'UNIT';
    public const UOM_KG = 'KG';
    public const UOM_G = 'G';
    public const UOM_L = 'L';
    public const UOM_ML = 'ML';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private ?string $name = null;

    #[ORM\Column(length: 64)]
    #[Assert\NotBlank]
    private ?string $sku = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $barcode = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Assert\PositiveOrZero]
    private ?string $cost = '0.00';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Assert\PositiveOrZero]
    private ?string $basePrice = '0.00';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 3, options: ['default' => '0.000'])]
    #[Assert\PositiveOrZero]
    private string $stockMin = '0.000';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 3, options: ['default' => '0.000'])]
    #[Assert\PositiveOrZero]
    private string $stock = '0.000';

    #[ORM\Column(length: 8, options: ['default' => self::UOM_UNIT])]
    #[Assert\Choice(choices: [self::UOM_UNIT, self::UOM_KG, self::UOM_G, self::UOM_L, self::UOM_ML])]
    private string $uomBase = self::UOM_UNIT;

    #[ORM\Column(options: ['default' => false])]
    private bool $allowsFractionalQty = false;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 3, nullable: true)]
    private ?string $qtyStep = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\ManyToOne(inversedBy: 'products')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Business $business = null;

    #[ORM\ManyToOne(inversedBy: 'products')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Category $category = null;

    /** @var Collection<int, StockMovement> */
    #[ORM\OneToMany(mappedBy: 'product', targetEntity: StockMovement::class, orphanRemoval: true)]
    private Collection $stockMovements;

    public function __construct()
    {
        $this->stockMovements = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getSku(): ?string
    {
        return $this->sku;
    }

    public function setSku(string $sku): self
    {
        $this->sku = $sku;

        return $this;
    }

    public function getBarcode(): ?string
    {
        return $this->barcode;
    }

    public function setBarcode(?string $barcode): self
    {
        $this->barcode = $barcode;

        return $this;
    }

    public function getCost(): ?string
    {
        return $this->cost;
    }

    public function setCost(string $cost): self
    {
        $this->cost = $cost;

        return $this;
    }

    public function getBasePrice(): ?string
    {
        return $this->basePrice;
    }

    public function setBasePrice(string $basePrice): self
    {
        $this->basePrice = $basePrice;

        return $this;
    }

    public function getStockMin(): string
    {
        return $this->stockMin;
    }

    public function setStockMin(string $stockMin): self
    {
        $this->stockMin = bcadd($stockMin, '0', 3);

        return $this;
    }

    public function getStock(): string
    {
        return $this->stock;
    }

    public function setStock(string $stock): self
    {
        $this->stock = bcadd($stock, '0', 3);

        return $this;
    }

    public function adjustStock(string $delta): self
    {
        $this->stock = bcadd($this->stock, $delta, 3);

        return $this;
    }

    public function getUomBase(): string
    {
        return $this->uomBase;
    }

    public function setUomBase(string $uomBase): self
    {
        $this->uomBase = $uomBase;
        $this->applyFractionalDefaults();

        return $this;
    }

    public function allowsFractionalQty(): bool
    {
        return $this->allowsFractionalQty;
    }

    public function setAllowsFractionalQty(bool $allowsFractionalQty): self
    {
        $this->allowsFractionalQty = $allowsFractionalQty;
        $this->applyFractionalDefaults();

        return $this;
    }

    public function getQtyStep(): ?string
    {
        return $this->qtyStep;
    }

    public function setQtyStep(?string $qtyStep): self
    {
        $this->qtyStep = $qtyStep !== null ? bcadd($qtyStep, '0', 3) : null;
        $this->applyFractionalDefaults();

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;

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

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    public function setCategory(?Category $category): self
    {
        $this->category = $category;

        return $this;
    }

    /**
     * @return Collection<int, StockMovement>
     */
    public function getStockMovements(): Collection
    {
        return $this->stockMovements;
    }

    public function addStockMovement(StockMovement $stockMovement): self
    {
        if (!$this->stockMovements->contains($stockMovement)) {
            $this->stockMovements->add($stockMovement);
            $stockMovement->setProduct($this);
        }

        return $this;
    }

    public function removeStockMovement(StockMovement $stockMovement): self
    {
        if ($this->stockMovements->removeElement($stockMovement)) {
            if ($stockMovement->getProduct() === $this) {
                $stockMovement->setProduct(null);
            }
        }

        return $this;
    }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function applyFractionalDefaults(): void
    {
        if ($this->uomBase === self::UOM_UNIT) {
            $this->allowsFractionalQty = false;
            $this->qtyStep = null;

            return;
        }

        if ($this->allowsFractionalQty === false) {
            $this->qtyStep = null;
        }

        if ($this->allowsFractionalQty && ($this->qtyStep === null || bccomp($this->qtyStep, '0', 3) <= 0)) {
            $this->qtyStep = '0.100';
        }
    }

    #[Assert\Callback]
    public function validateFractionalRules(ExecutionContextInterface $context): void
    {
        if ($this->uomBase === self::UOM_UNIT) {
            if ($this->allowsFractionalQty) {
                $context->buildViolation('Los productos por unidad no se pueden fraccionar.')
                    ->atPath('allowsFractionalQty')
                    ->addViolation();
            }

            if ($this->qtyStep !== null) {
                $context->buildViolation('qtyStep debe quedar vacío para productos UNIT.')
                    ->atPath('qtyStep')
                    ->addViolation();
            }

            return;
        }

        if ($this->allowsFractionalQty === false && $this->qtyStep !== null) {
            $context->buildViolation('Solo se define qtyStep cuando se permiten cantidades fraccionarias.')
                ->atPath('qtyStep')
                ->addViolation();
        }

        if ($this->allowsFractionalQty && ($this->qtyStep === null || bccomp($this->qtyStep, '0', 3) <= 0)) {
            $context->buildViolation('qtyStep debe ser mayor a cero para productos fraccionables.')
                ->atPath('qtyStep')
                ->addViolation();
        }
    }
}
