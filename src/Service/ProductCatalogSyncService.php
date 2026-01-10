<?php

namespace App\Service;

use App\Entity\Brand;
use App\Entity\Business;
use App\Entity\CatalogBrand;
use App\Entity\CatalogCategory;
use App\Entity\Category;
use App\Repository\BrandRepository;
use App\Repository\CategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\String\Slugger\SluggerInterface;

class ProductCatalogSyncService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CategoryRepository $categoryRepository,
        private readonly BrandRepository $brandRepository,
        private readonly SluggerInterface $slugger
    ) {
    }

    public function ensureLocalCategoryForCatalog(Business $business, CatalogCategory $catalogCategory): Category
    {
        $existing = $this->categoryRepository->findOneBy([
            'business' => $business,
            'catalogCategory' => $catalogCategory,
        ]);

        if ($existing instanceof Category) {
            return $existing;
        }

        $category = new Category();
        $category->setBusiness($business);
        $category->setName($catalogCategory->getName() ?? '');
        $category->setCatalogCategory($catalogCategory);

        $this->entityManager->persist($category);

        return $category;
    }

    public function ensureLocalBrandForCatalog(Business $business, CatalogBrand $catalogBrand): Brand
    {
        $slug = strtolower($this->slugger->slug($catalogBrand->getName() ?? '')->toString());

        $existing = $this->brandRepository->findOneBy([
            'business' => $business,
            'slug' => $slug,
        ]);

        if ($existing instanceof Brand) {
            return $existing;
        }

        $brand = new Brand();
        $brand->setBusiness($business);
        $brand->setName($catalogBrand->getName() ?? '');
        $brand->setSlug($slug);
        $brand->setLogoPath($catalogBrand->getLogoPath());
        $brand->setIsActive(true);

        $this->entityManager->persist($brand);

        return $brand;
    }
}
