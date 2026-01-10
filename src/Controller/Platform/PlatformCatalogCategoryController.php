<?php

namespace App\Controller\Platform;

use App\Entity\CatalogCategory;
use App\Form\CatalogCategoryType;
use App\Repository\CatalogCategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_PLATFORM_ADMIN')]
#[Route('/platform/catalog/categories', name: 'platform_catalog_category_')]
class PlatformCatalogCategoryController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, CatalogCategoryRepository $catalogCategoryRepository): Response
    {
        $name = trim((string) $request->query->get('name', ''));
        $status = (string) $request->query->get('status', 'all');

        $qb = $catalogCategoryRepository->createQueryBuilder('c');

        if ($name !== '') {
            $qb->andWhere('LOWER(c.name) LIKE :name')
                ->setParameter('name', '%'.mb_strtolower($name).'%');
        }

        if ($status === 'active') {
            $qb->andWhere('c.isActive = :active')
                ->setParameter('active', true);
        } elseif ($status === 'inactive') {
            $qb->andWhere('c.isActive = :active')
                ->setParameter('active', false);
        }

        $categories = $qb->orderBy('c.name', 'ASC')->getQuery()->getResult();

        return $this->render('platform/catalog_category/index.html.twig', [
            'categories' => $categories,
            'filters' => [
                'name' => $name,
                'status' => $status,
            ],
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $category = new CatalogCategory();
        $form = $this->createForm(CatalogCategoryType::class, $category);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($category);
            $this->entityManager->flush();

            $this->addFlash('success', 'Categoría global creada.');

            return $this->redirectToRoute('platform_catalog_category_index');
        }

        return $this->render('platform/catalog_category/form.html.twig', [
            'form' => $form,
            'category' => $category,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, CatalogCategory $category): Response
    {
        $form = $this->createForm(CatalogCategoryType::class, $category);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->addFlash('success', 'Categoría global actualizada.');

            return $this->redirectToRoute('platform_catalog_category_index');
        }

        return $this->render('platform/catalog_category/form.html.twig', [
            'form' => $form,
            'category' => $category,
        ]);
    }

    #[Route('/{id}/toggle', name: 'toggle', methods: ['POST'])]
    public function toggle(Request $request, CatalogCategory $category): Response
    {
        if (!$this->isCsrfTokenValid('toggle_catalog_category_'.$category->getId(), (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('platform_catalog_category_index');
        }

        $category->setIsActive(!$category->isActive());
        $this->entityManager->flush();

        return $this->redirectToRoute('platform_catalog_category_index');
    }

}
