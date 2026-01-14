<?php

namespace App\Repository;

use App\Entity\Business;
use App\Entity\BusinessUser;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BusinessUser>
 */
class BusinessUserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BusinessUser::class);
    }

    /**
     * @return BusinessUser[]
     */
    public function findActiveMembershipsForUser(User $user): array
    {
        return $this->createQueryBuilder('bu')
            ->andWhere('bu.user = :user')
            ->andWhere('bu.isActive = true')
            ->setParameter('user', $user)
            ->orderBy('bu.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findActiveMembership(User $user, Business $business): ?BusinessUser
    {
        return $this->findOneBy([
            'user' => $user,
            'business' => $business,
            'isActive' => true,
        ]);
    }

    public function countActiveAdminsForBusiness(Business $business): int
    {
        return (int) $this->createQueryBuilder('bu')
            ->select('COUNT(bu.id)')
            ->andWhere('bu.business = :business')
            ->andWhere('bu.isActive = true')
            ->andWhere('bu.role IN (:roles)')
            ->setParameter('business', $business)
            ->setParameter('roles', [BusinessUser::ROLE_OWNER, BusinessUser::ROLE_ADMIN])
            ->getQuery()
            ->getSingleScalarResult();
    }
}
