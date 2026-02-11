<?php

namespace App\Entity;

use App\Repository\ProductRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use App\Entity\StockMovement;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use App\Entity\Brand;
use App\Entity\CatalogProduct;
use Doctrine\DBAL\Types\Types;

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

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $characteristics = null;

    #[ORM\Column(name: 'search_text', type: Types::TEXT, nullable: true)]
    private ?string $searchText = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Assert\PositiveOrZero]
    private ?string $cost = '0.00';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Assert\PositiveOrZero]
    private ?string $basePrice = '0.00';

    #[ORM\Column(type: 'decimal', precision: 5, scale: 2, nullable: true)]
    #[Assert\PositiveOrZero]
    private ?string $ivaRate = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 3, options: ['default' => '0.000'])]
    #[Assert\PositiveOrZero]
    private string $stockMin = '0.000';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 3, options: ['default' => '0.000'])]
    #[Assert\PositiveOrZero]
    private string $stock = '0.000';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 3, nullable: true)]
    #[Assert\PositiveOrZero]
    private ?string $targetStock = null;

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

    #[ORM\ManyToOne(inversedBy: 'products')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Brand $brand = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?CatalogProduct $catalogProduct = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Supplier $supplier = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $supplierSku = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    #[Assert\PositiveOrZero]
    private ?string $purchasePrice = null;

    /** @var Collection<int, StockMovement> */
    #[ORM\OneToMany(mappedBy: 'product', targetEntity: StockMovement::class, orphanRemoval: true)]
    private Collection $stockMovements;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
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

    /**
     * @return array<string, string>
     */
    public function getCharacteristics(): array
    {
        return $this->characteristics ?? [];
    }

    /**
     * @param array<string, scalar|null> $characteristics
     */
    public function setCharacteristics(array $characteristics): self
    {
        $normalized = [];
        foreach ($characteristics as $key => $value) {
            $normalizedKey = trim((string) $key);
            $normalizedValue = trim((string) ($value ?? ''));
            if ($normalizedKey === '' || $normalizedValue === '') {
                continue;
            }

            $normalized[$normalizedKey] = $normalizedValue;
        }

        $this->characteristics = $normalized !== [] ? $normalized : null;

        return $this;
    }

    public function getSearchText(): ?string
    {
        return $this->searchText;
    }

    public function setSearchText(?string $searchText): self
    {
        $this->searchText = $searchText;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
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

    public function getIvaRate(): ?string
    {
        return $this->ivaRate;
    }

    public function setIvaRate(?string $ivaRate): self
    {
        $this->ivaRate = $ivaRate;

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

    public function getTargetStock(): ?string
    {
        return $this->targetStock;
    }

    public function setTargetStock(?string $targetStock): self
    {
        $this->targetStock = $targetStock !== null ? bcadd($targetStock, '0', 3) : null;

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

    public function getBrand(): ?Brand
    {
        return $this->brand;
    }

    public function setBrand(?Brand $brand): self
    {
        $this->brand = $brand;

        return $this;
    }

    public function getCatalogProduct(): ?CatalogProduct
    {
        return $this->catalogProduct;
    }

    public function setCatalogProduct(?CatalogProduct $catalogProduct): self
    {
        $this->catalogProduct = $catalogProduct;

        return $this;
    }

    public function getSupplier(): ?Supplier
    {
        return $this->supplier;
    }

    public function setSupplier(?Supplier $supplier): self
    {
        $this->supplier = $supplier;

        return $this;
    }

    public function getSupplierSku(): ?string
    {
        return $this->supplierSku;
    }

    public function setSupplierSku(?string $supplierSku): self
    {
        $this->supplierSku = $supplierSku;

        return $this;
    }

    public function getPurchasePrice(): ?string
    {
        return $this->purchasePrice;
    }

    public function setPurchasePrice(?string $purchasePrice): self
    {
        $this->purchasePrice = $purchasePrice !== null ? bcadd($purchasePrice, '0', 2) : null;

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
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
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
            $this->qtyStep = '0.001';
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
