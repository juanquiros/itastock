<?php

namespace App\Repository;

use App\Entity\Business;
use App\Entity\EmailPreference;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EmailPreference>
 */
class EmailPreferenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmailPreference::class);
    }

    public function getEffectivePreference(Business $business, User $user): EmailPreference
    {
        $userPreference = $this->findOneBy([
            'business' => $business,
            'user' => $user,
        ]);

        if ($userPreference instanceof EmailPreference) {
            return $userPreference;
        }

        $businessPreference = $this->findOneBy([
            'business' => $business,
            'user' => null,
        ]);

        if ($businessPreference instanceof EmailPreference) {
            return $businessPreference;
        }

        return $this->createDefaultPreference($business, null);
    }

    public function getBusinessPreference(Business $business): EmailPreference
    {
        $preference = $this->findOneBy([
            'business' => $business,
            'user' => null,
        ]);

        if ($preference instanceof EmailPreference) {
            return $preference;
        }

        return $this->createDefaultPreference($business, null);
    }

    private function createDefaultPreference(Business $business, ?User $user): EmailPreference
    {
        $preference = new EmailPreference();
        $preference->setBusiness($business);
        $preference->setUser($user);

        return $preference;
    }
}
