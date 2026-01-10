<?php

namespace App\EventSubscriber;

use App\Entity\Brand;
use App\Entity\CatalogBrand;
use App\Entity\CatalogCategory;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Symfony\Component\String\Slugger\SluggerInterface;

#[AsDoctrineListener(event: Events::prePersist)]
#[AsDoctrineListener(event: Events::preUpdate)]
class SlugSubscriber
{
    public function __construct(private readonly SluggerInterface $slugger)
    {
    }

    public function prePersist(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();

        if (!$this->supports($entity)) {
            return;
        }

        $this->applySlug($entity);
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $args->getObject();

        if (!$this->supports($entity)) {
            return;
        }

        $this->applySlug($entity);

        $em = $args->getObjectManager();
        $meta = $em->getClassMetadata($entity::class);
        $em->getUnitOfWork()->recomputeSingleEntityChangeSet($meta, $entity);
    }

    private function supports(object $entity): bool
    {
        return $entity instanceof CatalogCategory
            || $entity instanceof CatalogBrand
            || $entity instanceof Brand;
    }

    private function applySlug(CatalogCategory|CatalogBrand|Brand $entity): void
    {
        $name = $entity->getName();
        if ($name === null || $name === '') {
            return;
        }

        $slug = strtolower($this->slugger->slug($name)->toString());
        $entity->setSlug($slug);
    }
}
