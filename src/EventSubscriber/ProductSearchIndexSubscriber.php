<?php

namespace App\EventSubscriber;

use App\Entity\Product;
use App\Service\ProductSearchTextBuilder;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

class ProductSearchIndexSubscriber implements EventSubscriber
{
    public function __construct(private readonly ProductSearchTextBuilder $searchTextBuilder)
    {
    }

    public function getSubscribedEvents(): array
    {
        return [Events::prePersist, Events::preUpdate];
    }

    public function prePersist(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof Product) {
            return;
        }

        $entity->setSearchText($this->searchTextBuilder->buildForProduct($entity));
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof Product) {
            return;
        }

        $entity->setSearchText($this->searchTextBuilder->buildForProduct($entity));

        $uow = $args->getObjectManager()->getUnitOfWork();
        $meta = $args->getObjectManager()->getClassMetadata(Product::class);
        $uow->recomputeSingleEntityChangeSet($meta, $entity);
    }
}
