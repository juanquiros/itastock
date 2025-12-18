<?php

namespace App\Repository;

use App\Entity\Business;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findOneByResetToken(string $token): ?User
    {
        return $this->findOneBy(['resetToken' => $token]);
    }

    public function countAdminsByBusiness(Business $business): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('u.business = :business')
            ->andWhere('u.roles LIKE :adminRole')
            ->setParameter('business', $business)
            ->setParameter('adminRole', '%"ROLE_ADMIN"%')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
