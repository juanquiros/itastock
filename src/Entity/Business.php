<?php

namespace App\Entity;

use App\Repository\BusinessRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use DateTimeImmutable;
use App\Entity\Category;
use App\Entity\Brand;
use App\Entity\Product;
use App\Entity\Subscription;
use App\Entity\MercadoPagoSubscriptionLink;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: BusinessRepository::class)]
class Business
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 20, options: ['default' => 'ACTIVE'])]
    #[Assert\Choice(choices: ['ACTIVE', 'SUSPENDED'])]
    private string $status = 'ACTIVE';

    #[ORM\Column]
    private ?DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $barcodeScanSoundPath = null;

    /** @var Collection<int, User> */
    #[ORM\OneToMany(mappedBy: 'business', targetEntity: User::class, orphanRemoval: true)]
    private Collection $users;

    /** @var Collection<int, Category> */
    #[ORM\OneToMany(mappedBy: 'business', targetEntity: Category::class, orphanRemoval: true)]
    private Collection $categories;

    /** @var Collection<int, Product> */
    #[ORM\OneToMany(mappedBy: 'business', targetEntity: Product::class, orphanRemoval: true)]
    private Collection $products;

    /** @var Collection<int, Brand> */
    #[ORM\OneToMany(mappedBy: 'business', targetEntity: Brand::class, orphanRemoval: true)]
    private Collection $brands;

    #[ORM\OneToOne(mappedBy: 'business', targetEntity: Subscription::class, cascade: ['persist', 'remove'])]
    private ?Subscription $subscription = null;

    /** @var Collection<int, MercadoPagoSubscriptionLink> */
    #[ORM\OneToMany(mappedBy: 'business', targetEntity: MercadoPagoSubscriptionLink::class, orphanRemoval: true)]
    private Collection $mercadoPagoSubscriptionLinks;

    public function __construct()
    {
        $this->users = new ArrayCollection();
        $this->categories = new ArrayCollection();
        $this->products = new ArrayCollection();
        $this->brands = new ArrayCollection();
        $this->mercadoPagoSubscriptionLinks = new ArrayCollection();
        $this->createdAt = new DateTimeImmutable();
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

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getBarcodeScanSoundPath(): ?string
    {
        return $this->barcodeScanSoundPath;
    }

    public function setBarcodeScanSoundPath(?string $barcodeScanSoundPath): self
    {
        $this->barcodeScanSoundPath = $barcodeScanSoundPath;

        return $this;
    }

    /**
     * @return Collection<int, User>
     */
    public function getUsers(): Collection
    {
        return $this->users;
    }

    public function addUser(User $user): self
    {
        if (!$this->users->contains($user)) {
            $this->users->add($user);
            $user->setBusiness($this);
        }

        return $this;
    }

    public function removeUser(User $user): self
    {
        if ($this->users->removeElement($user)) {
            if ($user->getBusiness() === $this) {
                $user->setBusiness(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Category>
     */
    public function getCategories(): Collection
    {
        return $this->categories;
    }

    public function addCategory(Category $category): self
    {
        if (!$this->categories->contains($category)) {
            $this->categories->add($category);
            $category->setBusiness($this);
        }

        return $this;
    }

    public function removeCategory(Category $category): self
    {
        if ($this->categories->removeElement($category)) {
            if ($category->getBusiness() === $this) {
                $category->setBusiness(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Product>
     */
    public function getProducts(): Collection
    {
        return $this->products;
    }

    public function addProduct(Product $product): self
    {
        if (!$this->products->contains($product)) {
            $this->products->add($product);
            $product->setBusiness($this);
        }

        return $this;
    }

    public function removeProduct(Product $product): self
    {
        if ($this->products->removeElement($product)) {
            if ($product->getBusiness() === $this) {
                $product->setBusiness(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Brand>
     */
    public function getBrands(): Collection
    {
        return $this->brands;
    }

    public function addBrand(Brand $brand): self
    {
        if (!$this->brands->contains($brand)) {
            $this->brands->add($brand);
            $brand->setBusiness($this);
        }

        return $this;
    }

    public function removeBrand(Brand $brand): self
    {
        if ($this->brands->removeElement($brand)) {
            if ($brand->getBusiness() === $this) {
                $brand->setBusiness(null);
            }
        }

        return $this;
    }

    public function getSubscription(): ?Subscription
    {
        return $this->subscription;
    }

    public function setSubscription(?Subscription $subscription): self
    {
        // unset the owning side of the relation if necessary
        if ($subscription === null && $this->subscription !== null) {
            $this->subscription->setBusiness(null);
        }

        // set the owning side of the relation if necessary
        if ($subscription !== null && $subscription->getBusiness() !== $this) {
            $subscription->setBusiness($this);
        }

        $this->subscription = $subscription;

        return $this;
    }

    /**
     * @return Collection<int, MercadoPagoSubscriptionLink>
     */
    public function getMercadoPagoSubscriptionLinks(): Collection
    {
        return $this->mercadoPagoSubscriptionLinks;
    }

    public function addMercadoPagoSubscriptionLink(MercadoPagoSubscriptionLink $link): self
    {
        if (!$this->mercadoPagoSubscriptionLinks->contains($link)) {
            $this->mercadoPagoSubscriptionLinks->add($link);
            $link->setBusiness($this);
        }

        return $this;
    }

    public function removeMercadoPagoSubscriptionLink(MercadoPagoSubscriptionLink $link): self
    {
        if ($this->mercadoPagoSubscriptionLinks->removeElement($link)) {
            if ($link->getBusiness() === $this) {
                $link->setBusiness(null);
            }
        }

        return $this;
    }
}
