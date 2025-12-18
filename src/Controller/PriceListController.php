<?php

namespace App\Controller;

use App\Entity\Business;
use App\Entity\PriceList;
use App\Entity\PriceListItem;
use App\Entity\Product;
use App\Form\PriceListType;
use App\Repository\PriceListItemRepository;
use App\Repository\PriceListRepository;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/app/admin/price-lists', name: 'app_price_list_')]
class PriceListController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PriceListRepository $priceListRepository,
        private readonly PriceListItemRepository $priceListItemRepository,
        private readonly ProductRepository $productRepository,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $business = $this->requireBusiness();
        $query = $request->query->get('q');

        $qb = $this->priceListRepository->createQueryBuilder('pl')
            ->andWhere('pl.business = :business')
            ->setParameter('business', $business)
            ->orderBy('LOWER(pl.name)', 'ASC');

        if ($query !== null && trim($query) !== '') {
            $qb->andWhere('LOWER(pl.name) LIKE :term')
                ->setParameter('term', '%'.mb_strtolower(trim($query)).'%');
        }

        return $this->render('price_list/index.html.twig', [
            'priceLists' => $qb->getQuery()->getResult(),
            'query' => $query,
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $business = $this->requireBusiness();

        $priceList = new PriceList();
        $priceList->setBusiness($business);

        $form = $this->createForm(PriceListType::class, $priceList);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($priceList->isDefault()) {
                $this->priceListRepository->clearDefaultForBusiness($business);
            }

            $this->entityManager->persist($priceList);
            $this->entityManager->flush();

            $this->addFlash('success', 'Lista de precios creada.');

            return $this->redirectToRoute('app_price_list_index');
        }

        return $this->render('price_list/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, PriceList $priceList): Response
    {
        $business = $this->requireBusiness();
        $this->denyIfDifferentBusiness($priceList, $business);

        $form = $this->createForm(PriceListType::class, $priceList);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($priceList->isDefault()) {
                $this->priceListRepository->clearDefaultForBusiness($business);
                $priceList->setIsDefault(true);
            }

            $this->entityManager->flush();

            $this->addFlash('success', 'Lista de precios actualizada.');

            return $this->redirectToRoute('app_price_list_index');
        }

        return $this->render('price_list/edit.html.twig', [
            'form' => $form,
            'priceList' => $priceList,
        ]);
    }

    #[Route('/{id}', name: 'delete', methods: ['POST'])]
    public function delete(Request $request, PriceList $priceList): Response
    {
        $business = $this->requireBusiness();
        $this->denyIfDifferentBusiness($priceList, $business);

        if (!$this->isCsrfTokenValid('delete'.$priceList->getId(), $request->request->get('_token'))) {
            return $this->redirectToRoute('app_price_list_index');
        }

        $hasItems = $this->priceListItemRepository->count(['priceList' => $priceList]) > 0;
        if ($hasItems) {
            $this->addFlash('danger', 'No podés borrar una lista con precios cargados. Desactivala en su lugar.');

            return $this->redirectToRoute('app_price_list_index');
        }

        $this->entityManager->remove($priceList);
        $this->entityManager->flush();

        $this->addFlash('success', 'Lista eliminada.');

        return $this->redirectToRoute('app_price_list_index');
    }

    #[Route('/{id}/products', name: 'products', methods: ['GET', 'POST'])]
    public function products(Request $request, PriceList $priceList): Response
    {
        $business = $this->requireBusiness();
        $this->denyIfDifferentBusiness($priceList, $business);

        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = 25;
        $search = $request->query->get('q');

        $qb = $this->productRepository->createQueryBuilder('p')
            ->andWhere('p.business = :business')
            ->setParameter('business', $business)
            ->orderBy('LOWER(p.name)', 'ASC');

        if ($search !== null && trim($search) !== '') {
            $term = '%'.mb_strtolower(trim($search)).'%';
            $qb->andWhere('LOWER(p.name) LIKE :term OR LOWER(p.sku) LIKE :term OR LOWER(p.barcode) LIKE :term')
                ->setParameter('term', $term);
        }

        $qb->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        $paginator = new Paginator($qb);
        $products = iterator_to_array($paginator);

        if ($request->isMethod('POST')) {
            $prices = $request->request->all('prices');
            foreach ($prices as $value) {
                $value = trim((string) $value);
                if ($value !== '' && (float) $value < 0) {
                    $this->addFlash('danger', 'Los precios deben ser mayores o iguales a 0.');

                    return $this->redirectToRoute('app_price_list_products', ['id' => $priceList->getId(), 'page' => $page, 'q' => $search]);
                }
            }
            $this->persistPrices($priceList, $products, $prices);

            $this->addFlash('success', 'Precios guardados.');

            return $this->redirectToRoute('app_price_list_products', ['id' => $priceList->getId(), 'page' => $page, 'q' => $search]);
        }

        $currentPrices = $this->priceListItemRepository->findPricesForListAndProducts($priceList, $products);

        return $this->render('price_list/products.html.twig', [
            'priceList' => $priceList,
            'products' => $products,
            'currentPrices' => $currentPrices,
            'page' => $page,
            'pages' => (int) ceil($paginator->count() / $perPage),
            'query' => $search,
        ]);
    }

    private function persistPrices(PriceList $priceList, array $products, array $prices): void
    {
        $productById = [];
        foreach ($products as $product) {
            $productById[$product->getId()] = $product;
        }

        foreach ($prices as $productId => $priceValue) {
            $productId = (int) $productId;
            if (!isset($productById[$productId])) {
                continue;
            }

            $priceValue = trim((string) $priceValue);
            $product = $productById[$productId];

            if ($priceValue === '') {
                $item = $this->priceListItemRepository->findOneBy([
                    'priceList' => $priceList,
                    'product' => $product,
                ]);

                if ($item !== null) {
                    $this->entityManager->remove($item);
                }

                continue;
            }

            $priceAsFloat = (float) $priceValue;

            $item = $this->priceListItemRepository->findOneBy([
                'priceList' => $priceList,
                'product' => $product,
            ]);

            if ($item === null) {
                $item = new PriceListItem();
                $item->setBusiness($priceList->getBusiness());
                $item->setPriceList($priceList);
                $item->setProduct($product);
                $this->entityManager->persist($item);
            }

            $item->setPrice(number_format($priceAsFloat, 2, '.', ''));
        }

        $this->entityManager->flush();
    }

    private function requireBusiness(): Business
    {
        $business = $this->getUser()?->getBusiness();

        if (!$business instanceof Business) {
            throw new AccessDeniedException('No se puede gestionar listas sin un comercio.');
        }

        return $business;
    }

    private function denyIfDifferentBusiness(PriceList $priceList, Business $business): void
    {
        if ($priceList->getBusiness() !== $business) {
            throw new AccessDeniedException('Solo podés gestionar listas de tu comercio.');
        }
    }
}
