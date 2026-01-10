<?php

namespace App\Controller\Platform;

use App\Entity\CatalogBrand;
use App\Form\CatalogBrandType;
use App\Repository\CatalogBrandRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;

#[IsGranted('ROLE_PLATFORM_ADMIN')]
#[Route('/platform/catalog/brands', name: 'platform_catalog_brand_')]
class PlatformCatalogBrandController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, CatalogBrandRepository $catalogBrandRepository): Response
    {
        $name = trim((string) $request->query->get('name', ''));
        $status = (string) $request->query->get('status', 'all');

        $qb = $catalogBrandRepository->createQueryBuilder('b');

        if ($name !== '') {
            $qb->andWhere('LOWER(b.name) LIKE :name')
                ->setParameter('name', '%'.mb_strtolower($name).'%');
        }

        if ($status === 'active') {
            $qb->andWhere('b.isActive = :active')
                ->setParameter('active', true);
        } elseif ($status === 'inactive') {
            $qb->andWhere('b.isActive = :active')
                ->setParameter('active', false);
        }

        $brands = $qb->orderBy('b.name', 'ASC')->getQuery()->getResult();

        return $this->render('platform/catalog_brand/index.html.twig', [
            'brands' => $brands,
            'filters' => [
                'name' => $name,
                'status' => $status,
            ],
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request, SluggerInterface $slugger): Response
    {
        $brand = new CatalogBrand();
        $form = $this->createForm(CatalogBrandType::class, $brand);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->applySlug($brand, $slugger);
            $this->entityManager->persist($brand);
            $this->entityManager->flush();

            $this->addFlash('success', 'Marca global creada.');

            return $this->redirectToRoute('platform_catalog_brand_index');
        }

        return $this->render('platform/catalog_brand/form.html.twig', [
            'form' => $form,
            'brand' => $brand,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, CatalogBrand $brand, SluggerInterface $slugger): Response
    {
        $form = $this->createForm(CatalogBrandType::class, $brand);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->applySlug($brand, $slugger);
            $this->entityManager->flush();

            $this->addFlash('success', 'Marca global actualizada.');

            return $this->redirectToRoute('platform_catalog_brand_index');
        }

        return $this->render('platform/catalog_brand/form.html.twig', [
            'form' => $form,
            'brand' => $brand,
        ]);
    }

    #[Route('/{id}/toggle', name: 'toggle', methods: ['POST'])]
    public function toggle(Request $request, CatalogBrand $brand): Response
    {
        if (!$this->isCsrfTokenValid('toggle_catalog_brand_'.$brand->getId(), (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('platform_catalog_brand_index');
        }

        $brand->setIsActive(!$brand->isActive());
        $this->entityManager->flush();

        return $this->redirectToRoute('platform_catalog_brand_index');
    }

    private function applySlug(CatalogBrand $brand, SluggerInterface $slugger): void
    {
        $slug = strtolower($slugger->slug($brand->getName() ?? '')->toString());
        $brand->setSlug($slug);
    }
}
