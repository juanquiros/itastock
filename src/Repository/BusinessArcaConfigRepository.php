<?php

namespace App\Repository;

use App\Entity\Business;
use App\Entity\BusinessArcaConfig;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BusinessArcaConfig>
 */
class BusinessArcaConfigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BusinessArcaConfig::class);
    }

    public function getOrCreate(Business $business): BusinessArcaConfig
    {
        $config = $this->findOneBy(['business' => $business]);
        if (!$config) {
            $config = new BusinessArcaConfig();
            $config->setBusiness($business);
        }

        return $config;
    }
}
