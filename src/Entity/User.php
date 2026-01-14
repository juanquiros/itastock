<?php

namespace App\Entity;

use App\Repository\UserRepository;
use App\Entity\BusinessUser;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[UniqueEntity(fields: ['email'], message: 'Ya existe un usuario con este correo.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Email]
    private ?string $email = null;

    #[ORM\Column(type: 'json')]
    private array $roles = [];

    #[ORM\Column]
    private ?string $password = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private ?string $fullName = null;

    #[ORM\ManyToOne(inversedBy: 'users')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Business $business = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $resetToken = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $resetRequestedAt = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(nullable: true)]
    private ?int $posNumber = null;

    /** @var Collection<int, BusinessUser> */
    #[ORM\OneToMany(mappedBy: 'user', targetEntity: BusinessUser::class, orphanRemoval: true)]
    private Collection $businessUsers;

    public function __construct()
    {
        $this->businessUsers = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = mb_strtolower($email);

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    /**
     * @return string[]
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    /**
     * @param string[] $roles
     */
    public function setRoles(array $roles): self
    {
        $this->roles = $roles;

        return $this;
    }

    public function getPassword(): string
    {
        return (string) $this->password;
    }

    public function setPassword(string $password): self
    {
        $this->password = $password;

        return $this;
    }

    public function eraseCredentials(): void
    {
    }

    public function getFullName(): ?string
    {
        return $this->fullName;
    }

    public function setFullName(string $fullName): self
    {
        $this->fullName = $fullName;

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

    public function getResetToken(): ?string
    {
        return $this->resetToken;
    }

    public function setResetToken(?string $resetToken): self
    {
        $this->resetToken = $resetToken;

        return $this;
    }

    public function getResetRequestedAt(): ?\DateTimeImmutable
    {
        return $this->resetRequestedAt;
    }

    public function setResetRequestedAt(?\DateTimeImmutable $resetRequestedAt): self
    {
        $this->resetRequestedAt = $resetRequestedAt;

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

    public function getPosNumber(): ?int
    {
        return $this->posNumber;
    }

    public function setPosNumber(?int $posNumber): self
    {
        $this->posNumber = $posNumber;

        return $this;
    }

    /**
     * @return Collection<int, BusinessUser>
     */
    public function getBusinessMemberships(): Collection
    {
        return $this->businessUsers;
    }

    public function addBusinessMembership(BusinessUser $businessUser): self
    {
        if (!$this->businessUsers->contains($businessUser)) {
            $this->businessUsers->add($businessUser);
            $businessUser->setUser($this);
        }

        return $this;
    }

    public function removeBusinessMembership(BusinessUser $businessUser): self
    {
        if ($this->businessUsers->removeElement($businessUser)) {
            if ($businessUser->getUser() === $this) {
                $businessUser->setUser(null);
            }
        }

        return $this;
    }

    /**
     * @return Business[]
     */
    public function getBusinesses(): array
    {
        $businesses = [];
        foreach ($this->businessUsers as $membership) {
            $business = $membership->getBusiness();
            if ($business instanceof Business) {
                $businesses[] = $business;
            }
        }

        return $businesses;
    }
}
