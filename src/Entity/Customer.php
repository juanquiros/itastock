<?php

namespace App\Entity;

use App\Repository\CustomerRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use App\Entity\PriceList;

#[ORM\Entity(repositoryClass: CustomerRepository::class)]
#[ORM\Table(name: 'customers')]
class Customer
{
    public const TYPE_DNI = 'DNI';
    public const TYPE_CUIT = 'CUIT';
    public const TYPE_OTHER = 'OTRO';

    public const CUSTOMER_CONSUMIDOR_FINAL = 'CONSUMIDOR_FINAL';
    public const CUSTOMER_MINORISTA = 'MINORISTA';
    public const CUSTOMER_MAYORISTA = 'MAYORISTA';
    public const CUSTOMER_REVENDEDOR = 'REVENDEDOR';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Business $business = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Ingresá el nombre del cliente.')]
    private ?string $name = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $documentType = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Assert\Regex(pattern: '/^\d+$/', message: 'El número de documento debe ser numérico.', groups: ['document_number'])]
    private ?string $documentNumber = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Email(message: 'Ingresá un email válido.')]
    private ?string $email = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $address = null;

    #[ORM\Column(length: 20)]
    private ?string $customerType = null;

    #[ORM\Column(nullable: true)]
    private ?int $ivaConditionId = null;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?PriceList $priceList = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->customerType = self::CUSTOMER_CONSUMIDOR_FINAL;
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getDocumentType(): ?string
    {
        return $this->documentType;
    }

    public function setDocumentType(?string $documentType): self
    {
        $this->documentType = $documentType;

        return $this;
    }

    public function getDocumentNumber(): ?string
    {
        return $this->documentNumber;
    }

    public function setDocumentNumber(?string $documentNumber): self
    {
        $this->documentNumber = $documentNumber;

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): self
    {
        $this->phone = $phone;

        return $this;
    }


    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): self
    {
        $this->email = $email !== null ? mb_strtolower(trim($email)) : null;

        return $this;
    }

    public function getAddress(): ?string
    {        return $this->address;
    }

    public function setAddress(?string $address): self
    {
        $this->address = $address;

        return $this;
    }

    public function getCustomerType(): ?string
    {
        return $this->customerType;
    }

    public function setCustomerType(string $customerType): self
    {
        $this->customerType = $customerType;

        return $this;
    }

    public function getIvaConditionId(): ?int
    {
        return $this->ivaConditionId;
    }

    public function setIvaConditionId(?int $ivaConditionId): self
    {
        $this->ivaConditionId = $ivaConditionId;

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

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getPriceList(): ?PriceList
    {
        return $this->priceList;
    }

    public function setPriceList(?PriceList $priceList): self
    {
        $this->priceList = $priceList;

        return $this;
    }
}
