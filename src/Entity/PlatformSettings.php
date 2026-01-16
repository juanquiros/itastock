<?php

namespace App\Entity;

use App\Repository\PlatformSettingsRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PlatformSettingsRepository::class)]
class PlatformSettings
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $barcodeScanSoundPath = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $whatsappLink = null;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getWhatsappLink(): ?string
    {
        return $this->whatsappLink;
    }

    public function setWhatsappLink(?string $whatsappLink): self
    {
        $this->whatsappLink = $whatsappLink;

        return $this;
    }
}
