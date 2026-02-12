<?php

namespace App\Controller;

use App\Entity\Business;
use App\Entity\CatalogProduct;
use App\Entity\Product;
use App\Entity\StockMovement;
use App\Form\ProductType;
use App\Form\ProductImportType;
use App\Entity\User;
use App\Repository\BrandRepository;
use App\Repository\CatalogProductRepository;
use App\Repository\CategoryRepository;
use App\Repository\ProductRepository;
use App\Security\BusinessContext;
use App\Service\ProductCsvImportService;
use App\Service\ProductCatalogSyncService;
use App\Service\ProductSearchService;
use App\Service\SkuGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('BUSINESS_ADMIN')]
#[Route('/app/admin/products', name: 'app_product_')]
class ProductController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BusinessContext $businessContext,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        Request $request,
        ProductRepository $productRepository,
        CategoryRepository $categoryRepository,
        BrandRepository $brandRepository,
        ProductSearchService $productSearchService,
    ): Response
    {
        $business = $this->requireBusinessContext();
        $q = trim((string) $request->query->get('q'));
        $categoryIds = array_values(array_filter(array_map(
            static fn (string $value): int => (int) $value,
            $request->query->all('categories')
        )));
        $brandIds = array_values(array_filter(array_map(
            static fn (string $value): int => (int) $value,
            $request->query->all('brands')
        )));

        $products = $q !== ''
            ? $productSearchService->search($q, 200)
            : $productRepository->findForAdminFilters($business, null, null, null, $categoryIds, $brandIds);

        if ($q !== '' && ($categoryIds !== [] || $brandIds !== [])) {
            $products = array_values(array_filter($products, static function (Product $product) use ($categoryIds, $brandIds): bool {
                if ($categoryIds !== [] && !in_array($product->getCategory()?->getId(), $categoryIds, true)) {
                    return false;
                }

                if ($brandIds !== [] && !in_array($product->getBrand()?->getId(), $brandIds, true)) {
                    return false;
                }

                return true;
            }));
        }

        return $this->render('product/index.html.twig', [
            'products' => $products,
            'filters' => [
                'q' => $q,
                'categories' => $categoryIds,
                'brands' => $brandIds,
            ],
            'categories' => $categoryRepository->findBy(['business' => $business], ['name' => 'ASC']),
            'brands' => $brandRepository->findBy(['business' => $business], ['name' => 'ASC']),
        ]);
    }

    #[Route('/export.csv', name: 'export', methods: ['GET'])]
    public function export(ProductRepository $productRepository): Response
    {
        $business = $this->requireBusinessContext();

        $products = $productRepository->findBy(['business' => $business], ['name' => 'ASC']);

        $response = new StreamedResponse();
        $response->setCallback(static function () use ($products): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'sku',
                'barcode',
                'name',
                'cost',
                'basePrice',
                'stockMin',
                'stock',
                'isActive',
                'category',
                'brand',
                'characteristics',
                'ivaRate',
                'targetStock',
                'uomBase',
                'allowsFractionalQty',
                'qtyStep',
                'supplierSku',
                'purchasePrice',
                'searchText',
            ], ';');

            foreach ($products as $product) {
                $characteristics = $product->getCharacteristics();
                ksort($characteristics);

                fputcsv($handle, [
                    $product->getSku(),
                    $product->getBarcode(),
                    $product->getName(),
                    number_format((float) $product->getCost(), 2, '.', ''),
                    number_format((float) $product->getBasePrice(), 2, '.', ''),
                    number_format((float) $product->getStockMin(), 3, '.', ''),
                    number_format((float) $product->getStock(), 3, '.', ''),
                    $product->isActive() ? '1' : '0',
                    $product->getCategory()?->getName(),
                    $product->getBrand()?->getName(),
                    $characteristics !== [] ? json_encode($characteristics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                    $product->getIvaRate(),
                    $product->getTargetStock(),
                    $product->getUomBase(),
                    $product->allowsFractionalQty() ? '1' : '0',
                    $product->getQtyStep(),
                    $product->getSupplierSku(),
                    $product->getPurchasePrice(),
                    $product->getSearchText(),
                ], ';');
            }

            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="products.csv"');

        return $response;
    }


    #[Route('/import-template.csv', name: 'import_template', methods: ['GET'])]
    public function importTemplate(): Response
    {
        $response = new StreamedResponse();
        $response->setCallback(static function (): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'sku',
                'barcode',
                'name',
                'cost',
                'basePrice',
                'stockMin',
                'isActive',
                'category',
                'brand',
                'characteristics',
                'ivaRate',
                'targetStock',
                'uomBase',
                'allowsFractionalQty',
                'qtyStep',
                'supplierSku',
                'purchasePrice',
            ], ';');
            fputcsv($handle, [
                'REP-0001',
                '7791234567890',
                'Amortiguador delantero',
                '8500.00',
                '12500.00',
                '2.000',
                '1',
                'Suspensión',
                'Monroe',
                '{"lado":"Der-Izq","modelo_vehiculo":"208"}',
                '21.00',
                '5.000',
                'UNIT',
                '0',
                '',
                '',
                '',
            ], ';');
            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="products_import_template.csv"');

        return $response;
    }

    #[Route('/import', name: 'import', methods: ['GET', 'POST'])]
    public function import(Request $request, ProductCsvImportService $importService): Response
    {
        $business = $this->requireBusinessContext();
        $form = $this->createForm(ProductImportType::class);
        $form->handleRequest($request);

        $results = null;

        if ($form->isSubmitted() && $form->isValid()) {
            $file = $form->get('file')->getData();
            $dryRun = (bool) $form->get('dryRun')->getData();

            $results = $importService->import($file, $business, $this->requireUser(), $dryRun);

            $message = sprintf(
                'Importación finalizada: %d creados, %d actualizados, %d fallidos%s.',
                $results['created'],
                $results['updated'],
                count($results['failed']),
                $dryRun ? ' (sin aplicar cambios)' : ''
            );

            if (($results['fileErrors'] ?? []) !== [] || ($results['created'] === 0 && $results['updated'] === 0 && count($results['failed']) > 0)) {
                $this->addFlash('danger', $message);
            } elseif (count($results['failed']) > 0) {
                $this->addFlash('warning', $message);
            } else {
                $this->addFlash('success', $message);
            }

            foreach (($results['fileErrors'] ?? []) as $fileError) {
                $this->addFlash('danger', $fileError);
            }
        } elseif ($form->isSubmitted()) {
            $errors = [];
            foreach ($form->getErrors(true) as $error) {
                $errors[] = $error->getMessage();
            }

            if ($errors === []) {
                $errors[] = 'No se pudo procesar el archivo. Revisá el formato e intentá nuevamente.';
            }

            $this->addFlash('danger', 'No se pudo procesar la importación.');
            foreach (array_unique($errors) as $errorMessage) {
                $this->addFlash('danger', $errorMessage);
            }
        }

        return $this->render('product/import.html.twig', [
            'form' => $form,
            'results' => $results,
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        CatalogProductRepository $catalogProductRepository,
        ProductCatalogSyncService $catalogSyncService,
        SkuGenerator $skuGenerator,
        ProductRepository $productRepository
    ): Response
    {
        $business = $this->requireBusinessContext();

        $product = new Product();
        $product->setBusiness($business);
        if ($product->getSku() === null || $product->getSku() === '') {
            $product->setSku($skuGenerator->generateNextSkuForBusiness($business));
        }

        $form = $this->createForm(ProductType::class, $product, [
            'current_business' => $business,
            'show_stock' => true,
            'current_stock' => $product->getStock(),
            'catalog_product_id' => $product->getCatalogProduct()?->getId(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $this->validateSkuUniqueness($form, $product, $business, $productRepository);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $this->applyCatalogSelection($form->get('catalogProductId')->getData(), $product, $business, $catalogProductRepository, $catalogSyncService);
            $this->entityManager->persist($product);
            $this->handleStockAdjustment($product, (string) $form->get('stockAdjustment')->getData());

            $this->entityManager->flush();

            $this->addFlash('success', 'Producto creado.');

            return $this->redirectToRoute('app_product_index');
        }

        return $this->render('product/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        Product $product,
        CatalogProductRepository $catalogProductRepository,
        ProductCatalogSyncService $catalogSyncService,
        ProductRepository $productRepository
    ): Response
    {
        $business = $this->requireBusinessContext();
        $this->denyIfDifferentBusiness($product, $business);

        $form = $this->createForm(ProductType::class, $product, [
            'current_business' => $business,
            'show_stock' => true,
            'current_stock' => $product->getStock(),
            'catalog_product_id' => $product->getCatalogProduct()?->getId(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $this->validateSkuUniqueness($form, $product, $business, $productRepository);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $this->applyCatalogSelection($form->get('catalogProductId')->getData(), $product, $business, $catalogProductRepository, $catalogSyncService);
            $this->handleStockAdjustment($product, (string) $form->get('stockAdjustment')->getData());

            $this->entityManager->flush();

            $this->addFlash('success', 'Producto actualizado.');

            return $this->redirectToRoute('app_product_index');
        }

        return $this->render('product/edit.html.twig', [
            'form' => $form,
            'product' => $product,
        ]);
    }

    #[Route('/{id}', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, Product $product): Response
    {
        $business = $this->requireBusinessContext();
        $this->denyIfDifferentBusiness($product, $business);

        if ($this->isCsrfTokenValid('delete'.$product->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($product);
            $this->entityManager->flush();

            $this->addFlash('success', 'Producto eliminado.');
        }

        return $this->redirectToRoute('app_product_index');
    }

    private function handleStockAdjustment(Product $product, string $adjustment): void
    {
        $normalized = $adjustment === '' ? '0' : $adjustment;

        if (!preg_match('/^-?\d+(?:\.\d{1,3})?$/', $normalized)) {
            $this->addFlash('danger', 'Ingresá un número válido para el ajuste de stock.');

            return;
        }

        $normalized = bcadd($normalized, '0', 3);

        if (bccomp($normalized, '0', 3) === 0) {
            return;
        }

        $newStock = bcadd($product->getStock(), $normalized, 3);
        if (bccomp($newStock, '0', 3) < 0) {
            $this->addFlash('danger', 'El ajuste dejaría el stock en negativo.');

            return;
        }

        $product->adjustStock($normalized);

        $movement = new StockMovement();
        $movement->setProduct($product);
        $movement->setType(StockMovement::TYPE_ADJUST);
        $movement->setQty($normalized);
        $movement->setReference('Ajuste de stock');
        $movement->setCreatedBy($this->getUser());

        $this->entityManager->persist($movement);
    }

    private function applyCatalogSelection(
        mixed $catalogProductId,
        Product $product,
        Business $business,
        CatalogProductRepository $catalogProductRepository,
        ProductCatalogSyncService $catalogSyncService
    ): void {
        if ($catalogProductId === null || $catalogProductId === '') {
            return;
        }

        if (!is_numeric($catalogProductId)) {
            return;
        }

        $catalogProduct = $catalogProductRepository->find((int) $catalogProductId);
        if (!$catalogProduct instanceof CatalogProduct) {
            return;
        }

        $product->setCatalogProduct($catalogProduct);

        $category = $catalogSyncService->ensureLocalCategoryForCatalog($business, $catalogProduct->getCategory());
        $product->setCategory($category);

        if ($catalogProduct->getBrand() !== null) {
            $brand = $catalogSyncService->ensureLocalBrandForCatalog($business, $catalogProduct->getBrand());
            $product->setBrand($brand);
        }
    }

    private function validateSkuUniqueness(FormInterface $form, Product $product, Business $business, ProductRepository $productRepository): void
    {
        $sku = trim((string) $product->getSku());
        if ($sku === '') {
            return;
        }

        $existing = $productRepository->findOneByBusinessAndSku($business, $sku);
        if ($existing !== null && $existing->getId() !== $product->getId()) {
            $form->get('sku')->addError(new FormError('Ya existe un producto con este SKU en el comercio.'));
        }
    }

    private function requireBusinessContext(): Business
    {
        return $this->businessContext->requireCurrentBusiness();
    }

    private function requireUser(): User
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw new AccessDeniedException('Debés iniciar sesión.');
        }

        return $user;
    }

    private function denyIfDifferentBusiness(Product $product, Business $business): void
    {
        if ($product->getBusiness() !== $business) {
            throw new AccessDeniedException('Solo podés gestionar productos de tu comercio.');
        }
    }
}
