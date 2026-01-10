<?php

namespace App\Controller\Platform;

use App\Entity\CatalogProduct;
use App\Form\CatalogProductType;
use App\Repository\CatalogBrandRepository;
use App\Repository\CatalogCategoryRepository;
use App\Repository\CatalogProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_PLATFORM_ADMIN')]
#[Route('/platform/catalog/products', name: 'platform_catalog_product_')]
class PlatformCatalogProductController extends AbstractController
{
    private const PER_PAGE = 20;

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        Request $request,
        CatalogProductRepository $catalogProductRepository,
        CatalogCategoryRepository $catalogCategoryRepository,
        CatalogBrandRepository $catalogBrandRepository
    ): Response {
        $name = trim((string) $request->query->get('name', ''));
        $barcode = trim((string) $request->query->get('barcode', ''));
        $categoryId = (int) $request->query->get('category', 0);
        $brandId = (int) $request->query->get('brand', 0);
        $status = (string) $request->query->get('status', 'all');
        $page = max(1, (int) $request->query->get('page', 1));

        $qb = $catalogProductRepository->createQueryBuilder('p')
            ->leftJoin('p.category', 'c')
            ->addSelect('c')
            ->leftJoin('p.brand', 'b')
            ->addSelect('b');

        if ($name !== '') {
            $qb->andWhere('LOWER(p.name) LIKE :name')
                ->setParameter('name', '%'.mb_strtolower($name).'%');
        }

        if ($barcode !== '') {
            $qb->andWhere('p.barcode LIKE :barcode')
                ->setParameter('barcode', '%'.$barcode.'%');
        }

        if ($categoryId > 0) {
            $qb->andWhere('c.id = :categoryId')
                ->setParameter('categoryId', $categoryId);
        }

        if ($brandId > 0) {
            $qb->andWhere('b.id = :brandId')
                ->setParameter('brandId', $brandId);
        }

        if ($status === 'active') {
            $qb->andWhere('p.isActive = :active')
                ->setParameter('active', true);
        } elseif ($status === 'inactive') {
            $qb->andWhere('p.isActive = :active')
                ->setParameter('active', false);
        }

        $countQb = clone $qb;
        $total = (int) $countQb->select('COUNT(p.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();

        $products = $qb
            ->orderBy('p.name', 'ASC')
            ->setFirstResult(($page - 1) * self::PER_PAGE)
            ->setMaxResults(self::PER_PAGE)
            ->getQuery()
            ->getResult();

        $totalPages = (int) ceil($total / self::PER_PAGE);

        return $this->render('platform/catalog_product/index.html.twig', [
            'products' => $products,
            'categories' => $catalogCategoryRepository->findBy([], ['name' => 'ASC']),
            'brands' => $catalogBrandRepository->findBy([], ['name' => 'ASC']),
            'filters' => [
                'name' => $name,
                'barcode' => $barcode,
                'category' => $categoryId,
                'brand' => $brandId,
                'status' => $status,
            ],
            'pagination' => [
                'page' => $page,
                'total' => $total,
                'total_pages' => $totalPages,
            ],
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $product = new CatalogProduct();
        $form = $this->createForm(CatalogProductType::class, $product);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($product);
            $this->entityManager->flush();

            $this->addFlash('success', 'Producto global creado.');

            return $this->redirectToRoute('platform_catalog_product_index');
        }

        return $this->render('platform/catalog_product/form.html.twig', [
            'form' => $form,
            'product' => $product,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, CatalogProduct $product): Response
    {
        $form = $this->createForm(CatalogProductType::class, $product);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->addFlash('success', 'Producto global actualizado.');

            return $this->redirectToRoute('platform_catalog_product_index');
        }

        return $this->render('platform/catalog_product/form.html.twig', [
            'form' => $form,
            'product' => $product,
        ]);
    }
}
