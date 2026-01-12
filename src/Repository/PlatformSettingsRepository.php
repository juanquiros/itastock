<?php

namespace App\Repository;

use App\Entity\PlatformSettings;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlatformSettings>
 */
class PlatformSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlatformSettings::class);
    }

    public function getOrCreate(): PlatformSettings
    {
        $settings = $this->findOneBy([]);
        if ($settings instanceof PlatformSettings) {
            return $settings;
        }

        return new PlatformSettings();
    }
}
