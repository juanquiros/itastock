<?php

namespace App\Repository;

use App\Entity\PublicPage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PublicPage>
 */
class PublicPageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PublicPage::class);
    }

    public function findPublishedBySlug(string $slug): ?PublicPage
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.slug = :slug')
            ->andWhere('p.isPublished = :published')
            ->setParameter('slug', $slug)
            ->setParameter('published', true)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
